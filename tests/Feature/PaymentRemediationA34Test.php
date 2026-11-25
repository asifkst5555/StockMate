<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\RazorpayPaymentController;
use App\Models\Currency;
use App\Models\PaymentIntent;
use App\Models\PaymentLifecycleEvent;
use App\Models\PaymentWebhookEvent;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SadminSetting;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserStore;
use App\Repositories\SubscriptionRepository;
use App\Services\PaymentIntentService;
use App\Services\PaymentLifecycleService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class PaymentRemediationA34Test extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Plan $paidPlan = null;
    private ?Currency $currency = null;
    private array $originalSadminSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'a34_tA_' . $this->token;
        $this->tenantBId = 'a34_tB_' . $this->token;

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'store_id' => "a34_store_a_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
            [
                'id' => $this->tenantBId,
                'store_id' => "a34_store_b_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
        ]);

        $this->adminA = $this->createAdmin("A34AdminA_{$this->token}", "a34.a.{$this->token}@stockmate.test", $this->tenantAId);
        $this->adminB = $this->createAdmin("A34AdminB_{$this->token}", "a34.b.{$this->token}@stockmate.test", $this->tenantBId);

        $this->storeA = Store::create([
            'name' => "A34 Store A {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);
        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);

        $this->currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->paidPlan = Plan::create([
            'name' => "A34 Pro {$this->token}",
            'price' => 99.00,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => 3,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        $keys = [
            'stripe_enabled', 'stripe_key', 'stripe_secret', 'stripe_webhook_secret', 'stripe_mode',
            'paypal_enabled', 'paypal_client_id', 'paypal_secret', 'paypal_webhook_id', 'paypal_mode',
            'razorpay_enabled', 'razorpay_key', 'razorpay_secret', 'razorpay_webhook_secret', 'razorpay_mode',
            'paystack_enabled', 'paystack_key', 'paystack_secret', 'paystack_mode',
            'manual_payment_enabled', 'manual_payment_guide',
        ];
        foreach ($keys as $k) {
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

        $userIds = array_filter([$this->adminA?->id, $this->adminB?->id]);
        if (! empty($userIds)) {
            PaymentIntent::whereIn('user_id', $userIds)->delete();
            Subscription::whereIn('user_id', $userIds)->delete();
            Transaction::withoutGlobalScope('tenant')->whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
            User::withoutGlobalScope('tenant')->whereIn('id', $userIds)->forceDelete();
        }

        if ($this->storeA) {
            Store::where('id', $this->storeA->id)->delete();
        }
        if ($this->paidPlan) {
            Plan::where('id', $this->paidPlan->id)->delete();
        }

        DB::table('tenants')->whereIn('id', [$this->tenantAId, $this->tenantBId])->delete();

        parent::tearDown();
    }

    private function createAdmin(string $name, string $email, string $tenantId): User
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $name,
            'last_name' => 'Owner',
            'email' => $email,
            'phone' => '92309' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $user->assignRole(Role::ADMIN);

        return $user;
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

    // -------------------------------------------------------------------------
    // F-01: Webhook retry loss, transient failure recovery & in-flight dedup
    // -------------------------------------------------------------------------

    public function test_f01_webhook_transient_settlement_failure_is_retryable_and_succeeds_on_retry(): void
    {
        $whSecret = 'whsec_f01_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $whSecret]);

        $sessionId = 'cs_f01_' . $this->token;
        $eventId = 'evt_f01_' . $this->token;
        $intent = $this->createIntent(Subscription::TYPE_STRIPE, $sessionId);

        // Mock PaymentIntentService to fail ONCE transiently, then delegate to real settlement on retry
        $failOnce = true;
        $realService = app(PaymentIntentService::class);
        $mockService = $this->getMockBuilder(PaymentIntentService::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['settleIntent'])
            ->getMock();
        $mockService->method('settleIntent')->willReturnCallback(function (...$args) use (&$failOnce, $realService) {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('Transient DB deadlock');
            }

            return $realService->settleIntent(...$args);
        });
        $this->app->instance(PaymentWebhookController::class, new PaymentWebhookController($mockService, app(PaymentLifecycleService::class)));

        $payload = json_encode([
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
        ]);
        $ts = time();
        $sig = "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$payload}", $whSecret);

        // 1. First delivery hits transient failure -> 500 retryable_error, event marked FAILED (attempts=1), intent stays PENDING
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sig,
        ], $payload)->assertStatus(500)->assertJson(['status' => 'retryable_error']);

        $whEvent = PaymentWebhookEvent::where('provider', 'stripe')->where('event_id', $eventId)->first();
        $this->assertNotNull($whEvent);
        $this->assertSame(PaymentWebhookEvent::STATUS_FAILED, $whEvent->status);
        $this->assertSame(1, $whEvent->attempts);
        $this->assertSame(PaymentIntent::STATUS_PENDING, $intent->fresh()->status);

        // 2. Provider retry of exact same event_id succeeds -> 200 processed, attempts=2, intent PAID, 1 subscription
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sig,
        ], $payload)->assertStatus(200)->assertJson(['status' => 'processed']);

        $whEvent->refresh();
        $this->assertSame(PaymentWebhookEvent::STATUS_PROCESSED, $whEvent->status);
        $this->assertSame(2, $whEvent->attempts);
        $this->assertSame(PaymentIntent::STATUS_PAID, $intent->fresh()->status);
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('status', Subscription::ACTIVE)->get());

        // 3. Subsequent duplicate delivery -> 200 duplicate_ignored, still 1 subscription
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sig,
        ], $payload)->assertStatus(200)->assertJson(['status' => 'duplicate_ignored']);
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->get());

        // 4. Simulated concurrent in-flight PROCESSING event with active lease -> 200 in_flight (never settles twice)
        $inflightId = 'evt_inflight_' . $this->token;
        PaymentWebhookEvent::create([
            'provider' => 'stripe',
            'event_id' => $inflightId,
            'event_type' => 'checkout.session.completed',
            'provider_reference' => $sessionId,
            'status' => PaymentWebhookEvent::STATUS_PROCESSING,
            'processing_started_at' => Carbon::now(),
            'locked_until' => Carbon::now()->addMinutes(3),
            'processing_token' => bin2hex(random_bytes(16)),
            'attempts' => 1,
            'payload_hash' => hash('sha256', 'inflight'),
        ]);
        $inflightPayload = json_encode([
            'id' => $inflightId,
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => $sessionId, 'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 9900, 'currency' => 'usd']],
        ]);
        $inflightSig = "t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$inflightPayload}", $whSecret);
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $inflightSig,
        ], $inflightPayload)->assertStatus(200)->assertJson(['status' => 'in_flight']);
    }

    // -------------------------------------------------------------------------
    // F-02: Masked/blank secret preservation, webhook settings & authorization
    // -------------------------------------------------------------------------

    public function test_f02_payment_settings_preserve_blank_and_masked_secrets_and_enforce_superadmin_auth(): void
    {
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->firstOrFail();

        $origStripeSecret = 'sk_live_orig_' . $this->token;
        $origWhSecret = 'whsec_orig_' . $this->token;
        $origRzpWhSecret = 'rzp_wh_orig_' . $this->token;
        $origPpWhId = 'WH_PP_ORIG_' . $this->token;

        SadminSetting::updateOrCreate(['key' => 'stripe_secret'], ['value' => $origStripeSecret]);
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $origWhSecret]);
        SadminSetting::updateOrCreate(['key' => 'razorpay_webhook_secret'], ['value' => $origRzpWhSecret]);
        SadminSetting::updateOrCreate(['key' => 'paypal_webhook_id'], ['value' => $origPpWhId]);

        // 1. GET masks all secrets and exposes safe *_configured indicators
        $getResp = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/sadmin/payment-settings')
            ->assertStatus(200);
        $attrs = $getResp->json('data.attributes');
        $this->assertSame('', $attrs['stripe_secret']);
        $this->assertSame('', $attrs['stripe_webhook_secret']);
        $this->assertSame('', $attrs['razorpay_webhook_secret']);
        $this->assertSame('', $attrs['paypal_webhook_id']);
        $this->assertTrue($attrs['stripe_secret_configured']);
        $this->assertTrue($attrs['stripe_webhook_secret_configured']);
        $this->assertTrue($attrs['razorpay_webhook_secret_configured']);
        $this->assertTrue($attrs['paypal_webhook_id_configured']);
        $this->assertStringNotContainsString($origWhSecret, $getResp->getContent());

        // 2. Blank secret update preserves existing secret (does NOT fail validation or erase)
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/payment-settings', [
                'stripe_enabled' => 1,
                'stripe_key' => 'pk_test_' . $this->token,
                'stripe_secret' => '',
                'stripe_webhook_secret' => '',
                'stripe_mode' => 'sandbox',
            ])
            ->assertStatus(200);

        $this->assertSame($origStripeSecret, SadminSetting::where('key', 'stripe_secret')->value('value'));
        $this->assertSame($origWhSecret, SadminSetting::where('key', 'stripe_webhook_secret')->value('value'));

        // 3. Masked '********' update also preserves existing secret
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/payment-settings', [
                'stripe_enabled' => 1,
                'stripe_key' => 'pk_test_' . $this->token,
                'stripe_secret' => '********',
                'stripe_webhook_secret' => '********',
                'stripe_mode' => 'sandbox',
            ])
            ->assertStatus(200);

        $this->assertSame($origStripeSecret, SadminSetting::where('key', 'stripe_secret')->value('value'));
        $this->assertSame($origWhSecret, SadminSetting::where('key', 'stripe_webhook_secret')->value('value'));

        // 4. Tenant admin cannot update platform payment/webhook settings -> 403
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sadmin/payment-settings', [
                'stripe_webhook_secret' => 'attacker_secret',
            ])
            ->assertStatus(403);
        $this->assertSame($origWhSecret, SadminSetting::where('key', 'stripe_webhook_secret')->value('value'));

        // 5. Unauthenticated user -> 401
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/sadmin/payment-settings', ['stripe_enabled' => 1])->assertStatus(401);

        // 6. Missing webhook secrets fail closed (400) across all 4 endpoints
        SadminSetting::whereIn('key', ['stripe_webhook_secret', 'razorpay_webhook_secret', 'paypal_webhook_id', 'paystack_secret'])->delete();
        config([
            'payment.stripe.webhook_secret' => null,
            'payment.razorpay.webhook_secret' => null,
            'payment.paypal.webhook_id' => null,
            'services.paystack.secret' => null,
        ]);
        $this->postJson('/api/webhooks/stripe', ['id' => 'evt_1'])->assertStatus(400);
        $this->postJson('/api/webhooks/razorpay', ['event' => 'payment.captured'])->assertStatus(400);
        $this->postJson('/api/webhooks/paystack', ['event' => 'charge.success'])->assertStatus(400);
        $this->postJson('/api/webhooks/paypal', ['id' => 'WH-1'])->assertStatus(400);
    }

    // -------------------------------------------------------------------------
    // F-03: Razorpay backend session payload & frontend compatibility
    // -------------------------------------------------------------------------

    public function test_f03_razorpay_session_returns_backend_currency_and_publishable_key_and_failed_marks_intent(): void
    {
        SadminSetting::updateOrCreate(['key' => 'razorpay_key'], ['value' => 'rzp_pub_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'razorpay_secret'], ['value' => 'rzp_sec_never_expose_' . $this->token]);

        $orderId = 'order_f03_' . $this->token;
        $mock = $this->getMockBuilder(RazorpayPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['createRazorpayOrder'])
            ->getMock();
        $mock->method('createRazorpayOrder')->willReturn((object) ['id' => $orderId]);
        $this->app->instance(RazorpayPaymentController::class, $mock);

        $resp = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/razorpay/generate-session', ['plan_id' => $this->paidPlan->id])
            ->assertStatus(200);

        $data = $resp->json('data');
        $this->assertSame('USD', $data['currency']);
        $this->assertSame(9900, $data['amount']);
        $this->assertSame('rzp_pub_' . $this->token, $data['key']);
        $this->assertSame($orderId, $data['razorpayOrder']);
        $this->assertNotEmpty($data['intent_ref']);
        $this->assertStringNotContainsString('rzp_sec_never_expose_', $resp->getContent());

        // V-06: Failed GET redirect is read-only and does NOT mutate PENDING intent
        $this->get('/razorpay/failed?order_id=' . $orderId)
            ->assertRedirect('/app/user/payment-failed?status=false');

        $this->assertDatabaseHas('payment_intents', [
            'provider_reference' => $orderId,
            'status' => PaymentIntent::STATUS_PENDING,
        ]);

        // Verify frontend plansAction.js removed hardcoded INR and passes order_id on dismiss
        $jsSource = file_get_contents(base_path('resources/pos/src/store/action/plansAction.js'));
        $this->assertStringNotContainsString('currency: "INR"', $jsSource);
        $this->assertStringContainsString('currency: currency', $jsSource);
        $this->assertStringContainsString('/razorpay/failed?order_id=', $jsSource);
    }

    // -------------------------------------------------------------------------
    // F-04: Concurrent QueryException recovery & non-swallowing of unrelated DB errors
    // -------------------------------------------------------------------------

    public function test_f04_unique_collision_recovery_in_fresh_tx_and_unrelated_errors_not_swallowed(): void
    {
        $txId = 'tx_f04_' . $this->token;
        $intent = $this->createIntent(Subscription::TYPE_STRIPE, $txId);

        // Pre-seed the "winning" concurrent transaction + subscription
        $winningTx = Transaction::create([
            'tenant_id' => $this->tenantAId,
            'transaction_id' => $txId,
            'amount' => 99.00,
            'type' => Subscription::TYPE_STRIPE,
            'status' => Transaction::PAID,
            'user_id' => $this->adminA->id,
            'meta' => '{}',
        ]);
        $winningSub = Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->paidPlan->id,
            'transaction_id' => $winningTx->id,
            'payment_type' => Subscription::TYPE_STRIPE,
            'plan_amount' => 99,
            'payable_amount' => 99,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::ACTIVE,
            'is_paid' => 1,
        ]);

        // 1. Simulate MySQL 1062 duplicate key collision on transactions_transaction_id_type_unique
        $collisionService = $this->getMockBuilder(PaymentIntentService::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['insertPaidTransaction'])
            ->getMock();
        $collisionService->method('insertPaidTransaction')->willThrowException(
            new QueryException(
                'mysql',
                'insert into `transactions` ...',
                [],
                new \PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$txId}-1' for key 'transactions.transactions_transaction_id_type_unique'", 23000)
            )
        );

        // Temporarily test collision path: settleIntent with matching intent & user recovers cleanly
        $recovered = $collisionService->settleIntent($intent, $txId, 9900, 'USD', [], $this->adminA->id, $this->paidPlan->id, 'stripe');
        $this->assertTrue($recovered['idempotent']);
        $this->assertSame($winningTx->id, $recovered['transaction']->id);
        $this->assertSame($winningSub->id, $recovered['subscription']->id);
        $this->assertSame(PaymentIntent::STATUS_PAID, $intent->fresh()->status);

        // 2. Different transaction ID on already-settled intent MUST be rejected
        $differentRejected = false;
        try {
            $collisionService->settleIntent($intent, 'tx_different_' . $this->token, 9900, 'USD', [], $this->adminA->id, $this->paidPlan->id, 'stripe');
        } catch (UnprocessableEntityHttpException $e) {
            $differentRejected = true;
        }
        $this->assertTrue($differentRejected, 'Different transaction ID must not reuse an already settled intent.');

        // 3. Unrelated SQLSTATE 23000 (e.g. FK 1452) MUST NOT be swallowed
        $intent2 = $this->createIntent(Subscription::TYPE_STRIPE, 'tx_fk_' . $this->token);
        $fkService = $this->getMockBuilder(PaymentIntentService::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['insertPaidTransaction'])
            ->getMock();
        $fkService->method('insertPaidTransaction')->willThrowException(
            new QueryException(
                'mysql',
                'insert into `transactions` ...',
                [],
                new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails', 23000)
            )
        );

        $fkRethrown = false;
        try {
            $fkService->settleIntent($intent2, 'tx_fk_' . $this->token, 9900, 'USD', [], $this->adminA->id, $this->paidPlan->id, 'stripe');
        } catch (QueryException $e) {
            $fkRethrown = true;
        }
        $this->assertTrue($fkRethrown, 'Unrelated SQLSTATE 23000 errors must be rethrown, never swallowed.');
    }

    // -------------------------------------------------------------------------
    // F-05: Refund & dispute lifecycle (partial, full, overflow, dup, out-of-order, no auto-revoke)
    // -------------------------------------------------------------------------

    public function test_f05_refund_and_dispute_lifecycle_tracking_without_unauthorized_revocation(): void
    {
        $whSecret = 'whsec_f05_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $whSecret]);

        $sessionId = 'cs_f05_' . $this->token;
        $intent = $this->createIntent(Subscription::TYPE_STRIPE, $sessionId);

        // Settle payment first ($99.00 = 9900 minor units)
        $settled = app(PaymentIntentService::class)->settleIntent($intent, $sessionId, 9900, 'USD', [], $this->adminA->id, $this->paidPlan->id, 'stripe');
        $subscription = $settled['subscription'];

        $sendStripeWebhook = function (array $eventArray, ?string $overrideSig = null) use ($whSecret) {
            $body = json_encode($eventArray);
            $ts = time();
            $sig = $overrideSig ?? ("t={$ts},v1=" . hash_hmac('sha256', "{$ts}.{$body}", $whSecret));

            return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $sig,
            ], $body);
        };

        // 1. Invalid signature on refund webhook -> 400
        $sendStripeWebhook([
            'id' => 'evt_ref_bad_' . $this->token,
            'type' => 'charge.refunded',
            'data' => ['object' => ['id' => 'ch_1', 'currency' => 'usd', 'amount_refunded' => 3000, 'metadata' => ['session_id' => $sessionId]]],
        ], 't=' . time() . ',v1=invalid')->assertStatus(400);

        // 2. Partial refund ($30.00 = 3000 minor) -> recorded as partial_refund, requires_admin_review=true
        $partialEvent = [
            'id' => 'evt_ref_part_' . $this->token,
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_f05_' . $this->token,
                'currency' => 'usd',
                'amount_refunded' => 3000,
                'metadata' => ['session_id' => $sessionId],
                'refunds' => ['data' => [['id' => 're_part_' . $this->token, 'amount' => 3000]]],
            ]],
        ];
        $sendStripeWebhook($partialEvent)->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertDatabaseHas('payment_lifecycle_events', [
            'provider' => 'stripe',
            'provider_lifecycle_id' => 're_part_' . $this->token,
            'lifecycle_type' => PaymentLifecycleEvent::TYPE_PARTIAL_REFUND,
            'amount_minor' => 3000,
            'requires_admin_review' => 1,
        ]);

        // 3. Duplicate partial refund event -> deduplicated (still 1 row)
        $sendStripeWebhook($partialEvent)->assertStatus(200)->assertJson(['status' => 'duplicate_ignored']);
        $this->assertCount(1, PaymentLifecycleEvent::where('provider_lifecycle_id', 're_part_' . $this->token)->get());

        // 4. Refund exceeding remaining captured amount ($80.00 when $30 already refunded on $99) -> rejected & durably dead-lettered (200 dead_letter_recorded per N-03)
        $sendStripeWebhook([
            'id' => 'evt_ref_over_' . $this->token,
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_f05_' . $this->token,
                'currency' => 'usd',
                'metadata' => ['session_id' => $sessionId],
                'refunds' => ['data' => [['id' => 're_over_' . $this->token, 'amount' => 8000]]],
            ]],
        ])->assertStatus(200)->assertJson(['status' => 'dead_letter_recorded']);
        $this->assertDatabaseMissing('payment_lifecycle_events', ['provider_lifecycle_id' => 're_over_' . $this->token]);

        // 5. Remaining $69.00 refund brings total to $99.00 -> recorded as full_refund
        $sendStripeWebhook([
            'id' => 'evt_ref_full_' . $this->token,
            'type' => 'charge.refunded',
            'data' => ['object' => [
                'id' => 'ch_f05_' . $this->token,
                'currency' => 'usd',
                'metadata' => ['session_id' => $sessionId],
                'refunds' => ['data' => [['id' => 're_full_' . $this->token, 'amount' => 6900]]],
            ]],
        ])->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertDatabaseHas('payment_lifecycle_events', [
            'provider_lifecycle_id' => 're_full_' . $this->token,
            'lifecycle_type' => PaymentLifecycleEvent::TYPE_FULL_REFUND,
            'amount_minor' => 6900,
        ]);

        // 6. Dispute resolved (won) followed by out-of-order late dispute.created -> resolved state preserved
        $disputeId = 'dp_f05_' . $this->token;
        $sendStripeWebhook([
            'id' => 'evt_dp_won_' . $this->token,
            'type' => 'charge.dispute.closed',
            'data' => ['object' => [
                'id' => $disputeId,
                'status' => 'won',
                'amount' => 9900,
                'currency' => 'usd',
                'metadata' => ['session_id' => $sessionId],
            ]],
        ])->assertStatus(200)->assertJson(['status' => 'processed']);

        $sendStripeWebhook([
            'id' => 'evt_dp_late_open_' . $this->token,
            'type' => 'charge.dispute.created',
            'data' => ['object' => [
                'id' => $disputeId,
                'status' => 'needs_response',
                'amount' => 9900,
                'currency' => 'usd',
                'metadata' => ['session_id' => $sessionId],
            ]],
        ])->assertStatus(200);

        // Out-of-order late opened event must NOT create a new open dispute row after won
        $this->assertCount(1, PaymentLifecycleEvent::where('provider_lifecycle_id', $disputeId)->get());
        $this->assertDatabaseHas('payment_lifecycle_events', [
            'provider_lifecycle_id' => $disputeId,
            'lifecycle_type' => PaymentLifecycleEvent::TYPE_DISPUTE_WON,
            'status' => PaymentLifecycleEvent::STATUS_WON,
        ]);

        // 7. Policy compliance: Subscription was NOT automatically revoked; original Transaction preserved
        $this->assertEquals(Subscription::ACTIVE, (int) $subscription->fresh()->status);
        $this->assertEquals(Transaction::PAID, (int) $settled['transaction']->fresh()->status);
    }
}
