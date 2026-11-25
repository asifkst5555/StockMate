<?php

namespace Tests\Feature;

use App\Contracts\PaymentAlertNotifier;
use App\Http\Controllers\PaymentWebhookController;
use App\Models\Currency;
use App\Models\PaymentIntent;
use App\Models\PaymentLifecycleEvent;
use App\Models\PaymentReconciliation;
use App\Models\PaymentRecoveryAudit;
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
use App\Repositories\AdminRepository;
use App\Repositories\SubscriptionRepository;
use App\Services\PaymentIntentService;
use App\Services\PaymentLifecycleService;
use App\Services\PaymentMonitoringService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentRemediationA38Test extends TestCase
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
        $this->tenantAId = 'a38_tA_' . $this->token;

        DB::table('tenants')->insert([
            'id' => $this->tenantAId,
            'store_id' => "a38_store_a_{$this->token}",
            'created_at' => now(),
            'updated_at' => now(),
            'data' => '{}',
        ]);

        $this->adminA = User::withoutGlobalScope('tenant')->create([
            'first_name' => "A38AdminA_{$this->token}",
            'last_name' => 'Owner',
            'email' => "a38.a.{$this->token}@stockmate.test",
            'phone' => '92311' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'tenant_id' => $this->tenantAId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $this->adminA->assignRole(Role::ADMIN);

        $this->storeA = Store::create([
            'name' => "A38 Store A {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);
        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);

        $this->currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->paidPlan = Plan::create([
            'name' => "A38 Pro {$this->token}",
            'price' => 99.00,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => 3,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        foreach (['stripe_webhook_secret'] as $k) {
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

        PaymentRecoveryAudit::where('reason', 'like', "%{$this->token}%")->delete();
        PaymentReconciliation::where('provider_charge_id', 'like', "%{$this->token}%")->delete();
        PaymentLifecycleEvent::where('provider_event_id', 'like', "%{$this->token}%")->delete();
        PaymentWebhookEvent::where('event_id', 'like', "%{$this->token}%")->delete();
        Setting::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantAId)->delete();
        DB::table('payment_methods')->where('tenant_id', $this->tenantAId)->delete();
        DB::table('base_units')->where('tenant_id', $this->tenantAId)->delete();
        DB::table('mail_templates')->where('tenant_id', $this->tenantAId)->delete();
        DB::table('sms_settings')->where('tenant_id', $this->tenantAId)->delete();
        DB::table('sms_templates')->where('tenant_id', $this->tenantAId)->delete();
        DB::table('customers')->where('tenant_id', $this->tenantAId)->delete();
        DB::table('warehouses')->where('tenant_id', $this->tenantAId)->delete();

        if ($this->adminA) {
            PaymentIntent::where('user_id', $this->adminA->id)->delete();
            Subscription::where('user_id', $this->adminA->id)->delete();
            Transaction::withoutGlobalScope('tenant')->whereIn('user_id', [$this->adminA->id])->delete();
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
    // N-01: Pre-side-effect atomic ownership verification prevents stale worker execution
    // -------------------------------------------------------------------------

    public function test_n01_stale_worker_aborts_before_invoking_financial_side_effects(): void
    {
        $whSecret = 'whsec_n01_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $whSecret]);

        $sessionId = 'cs_n01_' . $this->token;
        $eventId = 'evt_n01_' . $this->token;
        $intent = PaymentIntent::create([
            'reference' => 'pi_n01_' . $this->token,
            'user_id' => $this->adminA->id,
            'tenant_id' => $this->tenantAId,
            'plan_id' => $this->paidPlan->id,
            'gateway' => Subscription::TYPE_STRIPE,
            'amount_minor' => 9900,
            'amount_decimal' => 99.00,
            'currency' => 'USD',
            'provider_reference' => $sessionId,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => Carbon::now()->addHour(),
        ]);

        // Spy on settleIntent: MUST NEVER be invoked if Worker A lost its lease before side effects!
        $settleCalled = 0;
        $intentServiceMock = $this->getMockBuilder(PaymentIntentService::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['settleIntent'])
            ->getMock();
        $intentServiceMock->method('settleIntent')->willReturnCallback(function () use (&$settleCalled) {
            $settleCalled++;

            return [];
        });

        // Simulate Worker A claiming event, then pausing while Worker B reclaims the lease before Worker A runs settleIntent
        $controller = new class($intentServiceMock) extends PaymentWebhookController {
            protected function beginWebhookProcessing(string $provider, string $eventId, string $eventType, ?string $providerReference, string $rawBody): array
            {
                $claim = parent::beginWebhookProcessing($provider, $eventId, $eventType, $providerReference, $rawBody);
                if ($claim['action'] === 'proceed' && $claim['event']) {
                    // Worker B reclaims the event with a brand-new token before Worker A reaches settleIntent!
                    $claim['event']->update([
                        'processing_token' => 'worker_b_token_' . bin2hex(random_bytes(8)),
                        'attempts' => 2,
                    ]);
                }

                return $claim;
            }
        };
        $this->app->instance(PaymentWebhookController::class, $controller);

        $this->sendSignedStripeWebhook([
            'id' => $eventId,
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $sessionId,
                    'payment_status' => 'paid',
                    'status' => 'complete',
                    'amount_total' => 9900,
                    'currency' => 'usd',
                    'client_reference_id' => $intent->reference,
                    'metadata' => ['intent_ref' => $intent->reference, 'user_id' => (string) $this->adminA->id, 'plan_id' => (string) $this->paidPlan->id],
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'stale_worker_aborted']);

        // Worker A NEVER invoked settleIntent and did NOT overwrite Worker B's state
        $this->assertSame(0, $settleCalled);
        $whRow = PaymentWebhookEvent::where('event_id', $eventId)->first();
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSING, $whRow->status);
        $this->assertSame(2, $whRow->attempts);
    }

    // -------------------------------------------------------------------------
    // N-02 & N-03: Stripe refund pagination reconciliation & Dead-letter HTTP 200
    // -------------------------------------------------------------------------

    public function test_n02_and_n03_refund_pagination_creates_persistent_reconciliation_and_dead_letter_returns_200(): void
    {
        $whSecret = 'whsec_n02_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $whSecret]);

        $sessionId = 'cs_n02_' . $this->token;
        $stripePiId = 'pi_stripe_n02_' . $this->token;
        $chargeId = 'ch_n02_' . $this->token;

        $intent = PaymentIntent::create([
            'reference' => 'pi_n02_' . $this->token,
            'user_id' => $this->adminA->id,
            'tenant_id' => $this->tenantAId,
            'plan_id' => $this->paidPlan->id,
            'gateway' => Subscription::TYPE_STRIPE,
            'amount_minor' => 9900,
            'amount_decimal' => 99.00,
            'currency' => 'USD',
            'provider_reference' => $sessionId,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => Carbon::now()->addHour(),
        ]);

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

        // 1. has_more = false -> NO reconciliation record created
        $this->sendSignedStripeWebhook([
            'id' => 'evt_n02_nomore_' . $this->token,
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => $chargeId,
                    'payment_intent' => $stripePiId,
                    'currency' => 'usd',
                    'refunds' => [
                        'has_more' => false,
                        'data' => [
                            ['id' => 're_n02_1_' . $this->token, 'amount' => 1000, 'currency' => 'usd', 'status' => 'succeeded'],
                            ['id' => 're_n02_fail_' . $this->token, 'amount' => 500, 'currency' => 'usd', 'status' => 'failed'],
                        ],
                    ],
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertDatabaseMissing('payment_reconciliations', ['provider_charge_id' => $chargeId]);
        $this->assertDatabaseMissing('payment_lifecycle_events', ['provider_lifecycle_id' => 're_n02_fail_' . $this->token]);

        // 2. has_more = true -> creates persistent pending reconciliation record (survives webhook completion)
        $this->sendSignedStripeWebhook([
            'id' => 'evt_n02_more1_' . $this->token,
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => $chargeId,
                    'payment_intent' => $stripePiId,
                    'currency' => 'usd',
                    'refunds' => [
                        'has_more' => true,
                        'data' => [
                            ['id' => 're_n02_2_' . $this->token, 'amount' => 2000, 'currency' => 'usd', 'status' => 'succeeded'],
                        ],
                    ],
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'processed']);

        $this->assertDatabaseHas('payment_reconciliations', [
            'provider' => 'stripe',
            'provider_charge_id' => $chargeId,
            'provider_payment_intent_id' => $stripePiId,
            'transaction_id' => $settled['transaction']->id,
            'reason_code' => PaymentReconciliation::REASON_STRIPE_REFUNDS_PAGINATED,
            'status' => PaymentReconciliation::STATUS_PENDING,
        ]);

        // 3. Duplicate has_more = true webhook -> does NOT create duplicate unresolved reconciliation record
        $this->sendSignedStripeWebhook([
            'id' => 'evt_n02_more2_' . $this->token,
            'type' => 'charge.refunded',
            'data' => [
                'object' => [
                    'id' => $chargeId,
                    'payment_intent' => $stripePiId,
                    'currency' => 'usd',
                    'refunds' => [
                        'has_more' => true,
                        'data' => [
                            ['id' => 're_n02_2_' . $this->token, 'amount' => 2000, 'currency' => 'usd', 'status' => 'succeeded'],
                        ],
                    ],
                ],
            ],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'processed']);

        $this->assertCount(1, PaymentReconciliation::where('provider_charge_id', $chargeId)->get());

        // 4. N-03: Already dead-lettered event returns HTTP 200 dead_letter_recorded
        $dlEvtId = 'evt_n03_dl_' . $this->token;
        PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => $dlEvtId,
            'event_type' => 'checkout.session.completed',
            'status' => PaymentWebhookEvent::STATUS_DEAD_LETTER,
            'attempts' => 10,
            'last_error' => 'Permanent error',
            'payload_hash' => hash('sha256', 'dl'),
            'dead_lettered_at' => now(),
        ]);
        $this->sendSignedStripeWebhook([
            'id' => $dlEvtId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => $sessionId]],
        ], $whSecret)->assertStatus(200)->assertJson(['status' => 'dead_letter_recorded']);
    }

    // -------------------------------------------------------------------------
    // N-04: Alerting isolation/throttling, Super Admin review & recovery, CLI commands
    // -------------------------------------------------------------------------

    public function test_n04_alert_isolation_throttling_superadmin_review_auditable_recovery_and_cli(): void
    {
        // 1. Alert failures NEVER break processing, and duplicate alerts are throttled
        $notifyCalls = 0;
        $flakyNotifier = new class($notifyCalls) implements PaymentAlertNotifier {
            public int $calls;
            public function __construct(int &$calls) { $this->calls = &$calls; }
            public function notify(string $alertKey, string $severity, string $message, array $context = []): void
            {
                $this->calls++;
                throw new \RuntimeException('Simulated alert channel outage');
            }
        };
        $monitor = new PaymentMonitoringService($flakyNotifier);
        $alertKey = 'test_alert_' . $this->token;
        Cache::forget('stockmate:payment_alert:' . hash('sha256', $alertKey));
        $this->assertFalse($monitor->dispatchAlert($alertKey, 'error', 'Test outage', ['secret_key' => 'NEVER_LOG'], 60));
        $this->assertSame(1, $flakyNotifier->calls);
        // Second call within throttle window is throttled (notifier not invoked again)
        $this->assertFalse($monitor->dispatchAlert($alertKey, 'error', 'Test outage', [], 60));
        $this->assertSame(1, $flakyNotifier->calls);

        // Seed dead-letter webhook, active-lease webhook, pending reconciliation, and lifecycle review
        $dlEvent = PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => 'evt_n04_dl_' . $this->token,
            'event_type' => 'checkout.session.completed',
            'status' => PaymentWebhookEvent::STATUS_DEAD_LETTER,
            'attempts' => 10,
            'last_error' => 'Simulated permanent error',
            'payload_hash' => hash('sha256', 'n04'),
            'dead_lettered_at' => now(),
        ]);
        $activeLeaseEvent = PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => 'evt_n04_active_' . $this->token,
            'event_type' => 'checkout.session.completed',
            'status' => PaymentWebhookEvent::STATUS_PROCESSING,
            'processing_started_at' => now(),
            'locked_until' => now()->addMinutes(3),
            'processing_token' => 'active_tok_' . $this->token,
            'attempts' => 1,
            'payload_hash' => hash('sha256', 'active'),
        ]);
        $recon = PaymentReconciliation::create([
            'provider' => 'stripe',
            'provider_charge_id' => 'ch_n04_' . $this->token,
            'reason_code' => PaymentReconciliation::REASON_STRIPE_REFUNDS_PAGINATED,
            'status' => PaymentReconciliation::STATUS_PENDING,
            'detected_at' => now(),
        ]);

        // 2. Unauthorized (unauth 401, tenant admin 403) blocked from review queue & recovery
        $this->getJson('/api/sadmin/payment-operations/review-queue')->assertStatus(401);
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/sadmin/payment-operations/review-queue')
            ->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/sadmin/payment-operations/webhooks/{$dlEvent->id}/requeue", [
                'confirm' => true,
                'reason' => 'Tenant should be blocked ' . $this->token,
            ])->assertStatus(403);

        // 3. Super Admin accesses safe review queue (no processing_token or secrets exposed)
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->firstOrFail();

        $queueResp = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/sadmin/payment-operations/review-queue')
            ->assertStatus(200);
        $this->assertStringNotContainsString('active_tok_' . $this->token, $queueResp->getContent());
        $this->assertGreaterThanOrEqual(1, $queueResp->json('data.counts.dead_letter_webhooks'));
        $this->assertGreaterThanOrEqual(1, $queueResp->json('data.counts.pending_reconciliations'));

        // 4. Recovery rejects active processing lease (409)
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/sadmin/payment-operations/webhooks/{$activeLeaseEvent->id}/requeue", [
                'confirm' => true,
                'reason' => 'Should fail due to active lease ' . $this->token,
            ])->assertStatus(409);

        // 5. Recovery requires explicit confirmation & records PaymentRecoveryAudit
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/sadmin/payment-operations/webhooks/{$dlEvent->id}/requeue", [
                'confirm' => false,
                'reason' => 'Missing confirm ' . $this->token,
            ])->assertStatus(422);

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/sadmin/payment-operations/webhooks/{$dlEvent->id}/requeue", [
                'confirm' => true,
                'reason' => 'Fixed root cause; ready for signed redelivery ' . $this->token,
            ])->assertStatus(200);

        $this->assertSame(PaymentWebhookEvent::STATUS_FAILED, $dlEvent->fresh()->status);
        $this->assertDatabaseHas('payment_recovery_audits', [
            'actor_user_id' => $superAdmin->id,
            'target_type' => PaymentRecoveryAudit::TARGET_WEBHOOK_EVENT,
            'target_id' => $dlEvent->id,
            'action' => 'prepare_retry',
        ]);

        // 6. Resolve reconciliation with audit trail
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/sadmin/payment-operations/reconciliations/{$recon->id}/resolve", [
                'confirm' => true,
                'reason' => 'Verified all Stripe refund pages manually ' . $this->token,
            ])->assertStatus(200);
        $this->assertSame(PaymentReconciliation::STATUS_RESOLVED, $recon->fresh()->status);

        // 7. Read-only Artisan commands succeed without modifying records
        $beforeCount = PaymentWebhookEvent::count();
        $this->assertSame(0, Artisan::call('stockmate:payments:dead-letters'));
        $this->assertSame(0, Artisan::call('stockmate:payments:reconciliation'));
        $this->assertSame(0, Artisan::call('stockmate:payments:review-queue'));
        $this->assertSame($beforeCount, PaymentWebhookEvent::count());
    }

    // -------------------------------------------------------------------------
    // N-05: New tenants receive zero dummy secret placeholders & accurate _configured flags
    // -------------------------------------------------------------------------

    public function test_n05_new_tenant_receives_no_dummy_secret_placeholders_and_reports_unconfigured(): void
    {
        $repo = app(AdminRepository::class);
        $repo->defaultSettings($this->tenantAId);

        // Verify no fake credentials were seeded
        $this->assertSame('', (string) Setting::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantAId)->where('key', 'stripe_secret')->value('value'));
        $this->assertSame('', (string) Setting::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantAId)->where('key', 'twillo_token')->value('value'));
        $this->assertSame('', (string) Setting::withoutGlobalScope('tenant')->where('tenant_id', $this->tenantAId)->where('key', 'smtp_password')->value('value'));

        // Give adminA an active subscription and verify GET /api/settings reports *_configured = false
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

        $resp = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/settings')
            ->assertStatus(200);

        $attrs = $resp->json('data.attributes');
        $this->assertFalse($attrs['stripe_secret_configured']);
        $this->assertFalse($attrs['twillo_token_configured']);
        $this->assertFalse($attrs['smtp_password_configured']);
        $this->assertArrayNotHasKey('stripe_secret', $attrs);
        $this->assertArrayNotHasKey('twillo_token', $attrs);
        $this->assertArrayNotHasKey('smtp_password', $attrs);
    }
}
