<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\StripePaymentController;
use App\Models\Currency;
use App\Models\PaymentIntent;
use App\Models\PaymentLifecycleEvent;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SadminSetting;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserStore;
use App\Repositories\SubscriptionRepository;
use App\Services\PaymentIntentService;
use App\Services\PaymentLifecycleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentRemediationA36Test extends TestCase
{
    private string $token;
    private string $tenantAId;
    private ?User $adminA = null;
    private ?Store $storeA = null;
    private ?Plan $paidPlan = null;
    private ?Currency $currency = null;
    private array $originalSadminSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'a36_tA_' . $this->token;

        DB::table('tenants')->insert([
            'id' => $this->tenantAId,
            'store_id' => "a36_store_a_{$this->token}",
            'created_at' => now(),
            'updated_at' => now(),
            'data' => '{}',
        ]);

        $this->adminA = User::withoutGlobalScope('tenant')->create([
            'first_name' => "A36AdminA_{$this->token}",
            'last_name' => 'Owner',
            'email' => "a36.a.{$this->token}@stockmate.test",
            'phone' => '92310' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'tenant_id' => $this->tenantAId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $this->adminA->assignRole(Role::ADMIN);

        $this->storeA = Store::create([
            'name' => "A36 Store A {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);
        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);

        $this->currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->paidPlan = Plan::create([
            'name' => "A36 Pro {$this->token}",
            'price' => 99.00,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => 3,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        foreach (['stripe_secret', 'stripe_webhook_secret'] as $k) {
            $row = SadminSetting::where('key', $k)->first();
            $this->originalSadminSettings[$k] = $row ? $row->value : null;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalSadminSettings as $k => $val) {
            if ($val === null) {
                SadminSetting::where('key', $k)->delete();
            } else {
                SadminSetting::updateOrCreate(['key' => $k], ['value' => $val]);
            }
        }

        PaymentLifecycleEvent::where('provider_event_id', 'like', "%{$this->token}%")->delete();
        PaymentWebhookEvent::where('event_id', 'like', "%{$this->token}%")->delete();
        Setting::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantAId)->delete();

        if ($this->adminA) {
            PaymentIntent::where('user_id', $this->adminA->id)->delete();
            Subscription::where('user_id', $this->adminA->id)->delete();
            Transaction::withoutGlobalScope('tenant')->where('user_id', $this->adminA->id)->delete();
            UserStore::where('user_id', $this->adminA->id)->delete();
            DB::table('model_has_roles')->where('model_id', $this->adminA->id)->where('model_type', User::class)->delete();
            User::withoutGlobalScope('tenant')->where('id', $this->adminA->id)->forceDelete();
        }
        if ($this->storeA) {
            Store::where('id', $this->storeA->id)->delete();
        }
        if ($this->paidPlan) {
            Plan::where('id', $this->paidPlan->id)->delete();
        }
        DB::table('tenants')->where('id', $this->tenantAId)->delete();

        parent::tearDown();
    }

    private function createIntent(int $gateway, string $providerRef, array $overrides = []): PaymentIntent
    {
        return PaymentIntent::create(array_merge([
            'reference' => 'pi_' . Str::lower(Str::random(12)),
            'user_id' => $this->adminA->id,
            'tenant_id' => $this->tenantAId,
            'plan_id' => $this->paidPlan->id,
            'gateway' => $gateway,
            'amount_minor' => 9900,
            'amount_decimal' => 99.00,
            'currency' => 'USD',
            'provider_reference' => $providerRef,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => Carbon::now()->addHour(),
        ], $overrides));
    }

    private function sendSignedStripeWebhook(array $event, string $whSecret, ?string $overrideSig = null)
    {
        $body = json_encode($event);
        $ts = time();
        $sig = $overrideSig ?? ("t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$body}", $whSecret));

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sig,
        ], $body);
    }

    // -------------------------------------------------------------------------
    // V-01 & V-04: Lease crash recovery, fencing token, attempt cap & dead-letter
    // -------------------------------------------------------------------------

    public function test_v01_and_v04_lease_stale_recovery_fencing_token_and_dead_letter(): void
    {
        $whSecret = 'whsec_v01_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $whSecret]);
        config(['payment.webhook.lease_seconds' => 180, 'payment.webhook.max_attempts' => 3]);

        $sessionId = 'cs_v01_' . $this->token;
        $stripePiId = 'pi_stripe_v01_' . $this->token;
        $intent = $this->createIntent(Subscription::TYPE_STRIPE, $sessionId);

        // 1. Invalid signature does NOT claim or insert any webhook event
        $badEvtId = 'evt_badsig_' . $this->token;
        $this->sendSignedStripeWebhook(['id' => $badEvtId, 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => $sessionId]]], $whSecret, 't=' . time() . ',v1=invalid')
            ->assertStatus(400);
        $this->assertDatabaseMissing('payment_webhook_events', ['event_id' => $badEvtId]);

        // 2. Expired processing lease (simulating PHP crash) IS atomically reclaimed & settles cleanly
        $staleEvtId = 'evt_stale_' . $this->token;
        $oldWorkerToken = 'old_worker_token_' . $this->token;
        $staleRow = PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => $staleEvtId,
            'event_type' => 'checkout.session.completed',
            'provider_reference' => $sessionId,
            'status' => PaymentWebhookEvent::STATUS_PROCESSING,
            'processing_started_at' => Carbon::now()->subMinutes(10),
            'locked_until' => Carbon::now()->subMinutes(7), // expired lease!
            'processing_token' => $oldWorkerToken,
            'attempts' => 1,
            'payload_hash' => hash('sha256', 'stale'),
        ]);

        $validCheckoutEvent = [
            'id' => $staleEvtId,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'payment_intent' => $stripePiId,
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 9900,
                    'currency' => 'usd',
                    'client_reference_id' => $intent->reference,
                    'metadata' => ['intent_ref' => $intent->reference, 'user_id' => (string) $this->adminA->id, 'plan_id' => (string) $this->paidPlan->id],
                ],
            ],
        ];

        // Worker B reclaims expired lease -> settles subscription -> marks PROCESSED (attempts = 2)
        $this->sendSignedStripeWebhook($validCheckoutEvent, $whSecret)
            ->assertStatus(200)
            ->assertJson(['status' => 'processed']);

        $staleRow->refresh();
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSED, $staleRow->status);
        $this->assertSame(2, $staleRow->attempts);
        $this->assertNull($staleRow->locked_until);
        $this->assertNull($staleRow->processing_token);
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('status', Subscription::ACTIVE)->get());

        // 3. Fencing protection: stale Worker A resuming with oldWorkerToken CANNOT overwrite Worker B's PROCESSED state
        $controller = new class(app(PaymentIntentService::class)) extends PaymentWebhookController {
            public function exposeMarkFailed(PaymentWebhookEvent $e, string $tok, string $err): string
            {
                return $this->markWebhookFailed($e, $tok, $err, false);
            }
        };
        $staleAttemptResult = $controller->exposeMarkFailed($staleRow, $oldWorkerToken, 'Late worker A failure');
        $this->assertSame('stale_ignored', $staleAttemptResult);
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSED, $staleRow->fresh()->status);

        // 4. Max attempts limit -> transitions to STATUS_DEAD_LETTER and preserves record & last_error
        $dlEvtId = 'evt_dl_' . $this->token;
        PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => $dlEvtId,
            'event_type' => 'checkout.session.completed',
            'provider_reference' => $sessionId,
            'status' => PaymentWebhookEvent::STATUS_FAILED,
            'attempts' => 3, // already at max_attempts=3
            'last_error' => 'Prior transient failure',
            'payload_hash' => hash('sha256', 'dl'),
        ]);

        $this->sendSignedStripeWebhook([
            'id' => $dlEvtId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => $sessionId, 'payment_status' => 'paid', 'status' => 'complete']],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'dead_letter_recorded']);

        $dlRow = PaymentWebhookEvent::where('event_id', $dlEvtId)->first();
        $this->assertSame(PaymentWebhookEvent::STATUS_DEAD_LETTER, $dlRow->status);
        $this->assertNotNull($dlRow->dead_lettered_at);
        $this->assertSame('Prior transient failure', $dlRow->last_error);
    }

    // -------------------------------------------------------------------------
    // V-02 & V-03: Stripe PaymentIntent correlation & multiple refunds
    // -------------------------------------------------------------------------

    public function test_v02_and_v03_stripe_pi_correlation_and_multiple_refunds_without_double_counting(): void
    {
        $whSecret = 'whsec_v02_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'stripe_secret'], ['value' => 'sk_test_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $whSecret]);

        // 1. Stripe generateSession attaches payment_intent_data.metadata.intent_ref
        $capturedPayload = null;
        $sessionId = 'cs_v02_' . $this->token;
        $stripePiId = 'pi_stripe_v02_' . $this->token;

        $stripeMock = $this->getMockBuilder(StripePaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['createStripeCheckoutSession'])
            ->getMock();
        $stripeMock->method('createStripeCheckoutSession')->willReturnCallback(function (array $payload) use (&$capturedPayload, $sessionId) {
            $capturedPayload = $payload;

            return (object) ['id' => $sessionId, 'url' => 'https://checkout.stripe.test/v02'];
        });
        $this->app->instance(StripePaymentController::class, $stripeMock);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/stripe/generate-session', ['plan_id' => $this->paidPlan->id])
            ->assertStatus(200);

        $intent = PaymentIntent::where('provider_reference', $sessionId)->firstOrFail();
        $this->assertSame($intent->reference, $capturedPayload['payment_intent_data']['metadata']['intent_ref']);

        // 2. Settle with Stripe PaymentIntent ID -> persisted on Transaction & PaymentIntent while preserving cs_*
        $settled = app(PaymentIntentService::class)->settleIntent(
            $intent,
            $sessionId,
            9900,
            'USD',
            [],
            $this->adminA->id,
            $this->paidPlan->id,
            'stripe',
            $stripePiId
        );
        $this->assertSame($sessionId, $settled['transaction']->transaction_id);
        $this->assertSame($stripePiId, $settled['transaction']->provider_payment_intent_id);
        $this->assertSame($stripePiId, $settled['intent']->provider_payment_intent_id);

        // 3. Real Stripe charge.refunded with TWO partial refunds (no metadata.session_id — correlates via payment_intent!)
        $this->sendSignedStripeWebhook([
            'id' => 'evt_multi_ref_' . $this->token,
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_v02_' . $this->token,
                    'payment_intent' => $stripePiId,
                    'currency' => 'usd',
                    'amount_refunded' => 5000, // cumulative; MUST NOT be counted as an extra delta
                    'refunds' => [
                        'has_more' => false,
                        'data' => [
                            ['id' => 're_1_' . $this->token, 'amount' => 2000, 'currency' => 'usd', 'status' => 'succeeded'],
                            ['id' => 're_2_' . $this->token, 'amount' => 3000, 'currency' => 'usd', 'status' => 'succeeded'],
                        ],
                    ],
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'processed']);

        $this->assertSame(5000, (int) PaymentLifecycleEvent::where('transaction_id', $settled['transaction']->id)->sum('amount_minor'));
        $this->assertDatabaseHas('payment_lifecycle_events', ['provider_lifecycle_id' => 're_1_' . $this->token, 'lifecycle_type' => PaymentLifecycleEvent::TYPE_PARTIAL_REFUND, 'amount_minor' => 2000]);
        $this->assertDatabaseHas('payment_lifecycle_events', ['provider_lifecycle_id' => 're_2_' . $this->token, 'lifecycle_type' => PaymentLifecycleEvent::TYPE_PARTIAL_REFUND, 'amount_minor' => 3000]);

        // 4. Third refund ($49.00) alongside already-seen re_1/re_2 -> deduplicates re_1/re_2 and records re_3 as full_refund (total=9900)
        $this->sendSignedStripeWebhook([
            'id' => 'evt_multi_ref2_' . $this->token,
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_v02_' . $this->token,
                    'payment_intent' => $stripePiId,
                    'currency' => 'usd',
                    'amount_refunded' => 9900,
                    'refunds' => [
                        'has_more' => false,
                        'data' => [
                            ['id' => 're_1_' . $this->token, 'amount' => 2000, 'currency' => 'usd', 'status' => 'succeeded'],
                            ['id' => 're_2_' . $this->token, 'amount' => 3000, 'currency' => 'usd', 'status' => 'succeeded'],
                            ['id' => 're_3_' . $this->token, 'amount' => 4900, 'currency' => 'usd', 'status' => 'succeeded'],
                        ],
                    ],
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'processed']);

        $this->assertSame(9900, (int) PaymentLifecycleEvent::where('transaction_id', $settled['transaction']->id)->sum('amount_minor'));
        $this->assertDatabaseHas('payment_lifecycle_events', ['provider_lifecycle_id' => 're_3_' . $this->token, 'lifecycle_type' => PaymentLifecycleEvent::TYPE_FULL_REFUND, 'amount_minor' => 4900]);

        // 5. Missing refunds.data payload MUST NOT treat cumulative amount_refunded as a delta -> dead_letter_recorded 200 (N-03)
        $this->sendSignedStripeWebhook([
            'id' => 'evt_missing_ref_' . $this->token,
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => 'ch_v02_' . $this->token,
                    'payment_intent' => $stripePiId,
                    'currency' => 'usd',
                    'amount_refunded' => 9900,
                    'refunds' => ['data' => []],
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'dead_letter_recorded']);

        // 6. Real Stripe charge.dispute.created correlates via payment_intent (no metadata.session_id)
        $this->sendSignedStripeWebhook([
            'id' => 'evt_dp_v02_' . $this->token,
            'type' => 'charge.dispute.created',
            'data' => [
                'object' => [
                    'id' => 'dp_v02_' . $this->token,
                    'payment_intent' => $stripePiId,
                    'amount' => 9900,
                    'currency' => 'usd',
                    'status' => 'needs_response',
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertDatabaseHas('payment_lifecycle_events', [
            'provider_lifecycle_id' => 'dp_v02_' . $this->token,
            'transaction_id' => $settled['transaction']->id,
            'lifecycle_type' => PaymentLifecycleEvent::TYPE_DISPUTE_OPENED,
        ]);

        // 7. Unknown PaymentIntent ID rejected safely (dead_letter_recorded 200 per N-03)
        $this->sendSignedStripeWebhook([
            'id' => 'evt_unk_pi_' . $this->token,
            'type' => 'refund.created',
            'data' => [
                'object' => [
                    'id' => 're_unk_' . $this->token,
                    'payment_intent' => 'pi_nonexistent_' . $this->token,
                    'amount' => 1000,
                    'currency' => 'usd',
                    'status' => 'succeeded',
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'dead_letter_recorded']);
    }

    // -------------------------------------------------------------------------
    // V-05: Tenant settings update never echoes secrets
    // -------------------------------------------------------------------------

    public function test_v05_tenant_settings_update_never_echoes_secrets_and_preserves_non_secrets(): void
    {
        // Give adminA an active subscription so CheckSubscription allows /api/settings
        Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->paidPlan->id,
            'payment_type' => Subscription::TYPE_FREE,
            'plan_amount' => 0,
            'payable_amount' => 0,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::ACTIVE,
        ]);

        $secretVal = 'sk_tenant_secret_no_echo_' . $this->token;
        $smtpVal = 'smtp_pass_no_echo_' . $this->token;

        $resp = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/settings', [
                'store_email' => 'shop@example.test',
                'stripe_secret' => $secretVal,
                'smtp_password' => $smtpVal,
                'stripe_webhook_secret' => 'cannot_touch_platform_wh',
            ])
            ->assertStatus(200);

        $this->assertStringNotContainsString($secretVal, $resp->getContent());
        $this->assertStringNotContainsString($smtpVal, $resp->getContent());
        $this->assertStringNotContainsString('cannot_touch_platform_wh', $resp->getContent());
        $this->assertSame('shop@example.test', $resp->json('data.attributes.store_email'));
        $this->assertDatabaseHas('settings', [
            'tenant_id' => $this->tenantAId,
            'key' => 'store_email',
            'value' => 'shop@example.test',
        ]);
        $this->assertDatabaseMissing('sadmin_settings', [
            'key' => 'stripe_webhook_secret',
            'value' => 'cannot_touch_platform_wh',
        ]);
    }

    // -------------------------------------------------------------------------
    // V-06: Public GET /failed checkout routes are strictly read-only
    // -------------------------------------------------------------------------

    public function test_v06_public_failed_routes_are_read_only_and_never_mutate_intents(): void
    {
        $stIntent = $this->createIntent(Subscription::TYPE_STRIPE, 'cs_ro_' . $this->token);
        $ppIntent = $this->createIntent(Subscription::TYPE_PAYPAL, 'PP_RO_' . $this->token);
        $rzpIntent = $this->createIntent(Subscription::TYPE_RAZORPAY, 'order_ro_' . $this->token);
        $paidIntent = $this->createIntent(Subscription::TYPE_STRIPE, 'cs_paid_ro_' . $this->token, ['status' => PaymentIntent::STATUS_PAID]);

        $this->get('/stripe/failed/?session_id=' . $stIntent->provider_reference)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->get('/paypal/failed/?token=' . $ppIntent->provider_reference)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->get('/razorpay/failed/?order_id=' . $rzpIntent->provider_reference)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->get('/stripe/failed/?session_id=' . $paidIntent->provider_reference)
            ->assertRedirect('/app/user/payment-failed?status=false');

        $this->assertSame(PaymentIntent::STATUS_PENDING, $stIntent->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_PENDING, $ppIntent->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_PENDING, $rzpIntent->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_PAID, $paidIntent->fresh()->status);
    }
}
