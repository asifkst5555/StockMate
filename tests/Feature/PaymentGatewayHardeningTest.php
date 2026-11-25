<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\PaypalPaymentController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\RazorpayPaymentController;
use App\Http\Controllers\StripePaymentController;
use App\Models\Currency;
use App\Models\PaymentIntent;
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
use App\Services\CurrencyMinorUnit;
use App\Services\PaymentIntentService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentGatewayHardeningTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $paidPlan = null;
    private ?Plan $altPlan = null;
    private ?Currency $currency = null;

    private array $originalSadminSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'a32_tA_' . $this->token;
        $this->tenantBId = 'a32_tB_' . $this->token;

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'store_id' => "a32_store_a_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
            [
                'id' => $this->tenantBId,
                'store_id' => "a32_store_b_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
        ]);

        $this->adminA = $this->createAdmin("A32AdminA_{$this->token}", "a32.a.{$this->token}@stockmate.test", $this->tenantAId);
        $this->adminB = $this->createAdmin("A32AdminB_{$this->token}", "a32.b.{$this->token}@stockmate.test", $this->tenantBId);

        $this->storeA = Store::create([
            'name' => "A32 Store A {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);
        $this->storeB = Store::create([
            'name' => "A32 Store B {$this->token}",
            'tenant_id' => $this->tenantBId,
            'user_id' => $this->adminB->id,
            'status' => 1,
        ]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $this->currency = Currency::where('code', 'USD')->first() ?: Currency::first();

        $this->paidPlan = Plan::create([
            'name' => "A32 Pro {$this->token}",
            'price' => 99.00,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => 3,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        $this->altPlan = Plan::create([
            'name' => "A32 Enterprise {$this->token}",
            'price' => 199.00,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => 10,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        $keys = [
            'stripe_secret', 'stripe_webhook_secret',
            'paypal_client_id', 'paypal_secret', 'paypal_mode', 'paypal_webhook_id',
            'razorpay_key', 'razorpay_secret', 'razorpay_webhook_secret',
            'paystack_key', 'paystack_secret',
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

        $storeIds = array_filter([$this->storeA?->id, $this->storeB?->id]);
        if (! empty($storeIds)) {
            Store::whereIn('id', $storeIds)->delete();
        }

        $planIds = array_filter([$this->paidPlan?->id, $this->altPlan?->id]);
        if (! empty($planIds)) {
            Plan::whereIn('id', $planIds)->delete();
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
            'phone' => '92308' . random_int(1000000, 9999999),
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
            'currency' => strtoupper($this->currency->code),
            'provider_reference' => $providerRef,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => Carbon::now()->addHour(),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Task 1: Database-level idempotency unique constraints
    // -------------------------------------------------------------------------

    public function test_database_unique_constraints_prevent_duplicate_transactions_and_subscriptions(): void
    {
        $txId = 'db_unique_tx_' . $this->token;

        $tx1 = Transaction::create([
            'tenant_id' => $this->tenantAId,
            'transaction_id' => $txId,
            'amount' => 99.00,
            'type' => Subscription::TYPE_STRIPE,
            'status' => Transaction::PAID,
            'user_id' => $this->adminA->id,
            'meta' => '{}',
        ]);

        // Duplicate (transaction_id, type) MUST be rejected at the database level
        $txUniqueTriggered = false;
        try {
            Transaction::create([
                'tenant_id' => $this->tenantAId,
                'transaction_id' => $txId,
                'amount' => 99.00,
                'type' => Subscription::TYPE_STRIPE,
                'status' => Transaction::PAID,
                'user_id' => $this->adminA->id,
                'meta' => '{}',
            ]);
        } catch (QueryException $e) {
            $txUniqueTriggered = true;
        }
        $this->assertTrue($txUniqueTriggered, 'UNIQUE(transaction_id, type) must reject duplicate inserts.');

        // Duplicate subscriptions.transaction_id MUST be rejected at the database level
        Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->paidPlan->id,
            'transaction_id' => $tx1->id,
            'payment_type' => Subscription::TYPE_STRIPE,
            'plan_amount' => 99,
            'payable_amount' => 99,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::ACTIVE,
            'is_paid' => 1,
        ]);

        $subUniqueTriggered = false;
        try {
            Subscription::create([
                'user_id' => $this->adminA->id,
                'plan_id' => $this->paidPlan->id,
                'transaction_id' => $tx1->id,
                'payment_type' => Subscription::TYPE_STRIPE,
                'plan_amount' => 99,
                'payable_amount' => 99,
                'plan_frequency' => Plan::MONTHLY,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'status' => Subscription::ACTIVE,
                'is_paid' => 1,
            ]);
        } catch (QueryException $e) {
            $subUniqueTriggered = true;
        }
        $this->assertTrue($subUniqueTriggered, 'UNIQUE(subscriptions.transaction_id) must reject duplicate subscriptions.');

        // Multiple NULL transaction_id rows (Free/Manual subscriptions) MUST still be allowed
        $free1 = Subscription::create([
            'user_id' => $this->adminB->id,
            'plan_id' => $this->paidPlan->id,
            'transaction_id' => null,
            'payment_type' => Subscription::TYPE_MANUAL,
            'plan_amount' => 99,
            'payable_amount' => 99,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::PENDING,
        ]);
        $free2 = Subscription::create([
            'user_id' => $this->adminB->id,
            'plan_id' => $this->altPlan->id,
            'transaction_id' => null,
            'payment_type' => Subscription::TYPE_MANUAL,
            'plan_amount' => 199,
            'payable_amount' => 199,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::PENDING,
        ]);
        $this->assertNotEquals($free1->id, $free2->id);
    }

    // -------------------------------------------------------------------------
    // Task 5: Currency-aware minor-unit conversion (no unconditional * 100)
    // -------------------------------------------------------------------------

    public function test_currency_minor_unit_handles_zero_two_and_three_decimal_currencies(): void
    {
        // Zero-decimal currencies (JPY, KRW, VND) -> multiplier = 1
        $this->assertSame(1500, CurrencyMinorUnit::toMinorUnits(1500, 'JPY'));
        $this->assertSame(1500.0, CurrencyMinorUnit::fromMinorUnits(1500, 'JPY'));
        $this->assertSame(50000, CurrencyMinorUnit::toMinorUnits(50000, 'KRW'));
        $this->assertSame('1500', CurrencyMinorUnit::formatDecimal(1500, 'JPY', 'paypal'));

        // Two-decimal currencies (USD, EUR, BDT) -> multiplier = 100
        $this->assertSame(9900, CurrencyMinorUnit::toMinorUnits(99.00, 'USD'));
        $this->assertSame(9950, CurrencyMinorUnit::toMinorUnits(99.50, 'BDT'));
        $this->assertSame('99.00', CurrencyMinorUnit::formatDecimal(99.00, 'USD', 'paypal'));
        $this->assertSame(9899, CurrencyMinorUnit::parseDecimalToMinorUnits('98.99', 'USD', 'paypal'));

        // Three-decimal currencies (KWD, BHD) -> multiplier = 1000
        $this->assertSame(12500, CurrencyMinorUnit::toMinorUnits(12.500, 'KWD'));
        $this->assertSame(12.5, CurrencyMinorUnit::fromMinorUnits(12500, 'KWD'));
    }

    // -------------------------------------------------------------------------
    // Task 2: generateSession creates server PaymentIntent & ignores tampered amount
    // -------------------------------------------------------------------------

    public function test_all_four_gateways_create_server_intent_and_ignore_client_amount_tampering(): void
    {
        SadminSetting::updateOrCreate(['key' => 'stripe_secret'], ['value' => 'sk_test_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'paypal_client_id'], ['value' => 'pp_cid_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'paypal_secret'], ['value' => 'pp_sec_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'razorpay_key'], ['value' => 'rzp_key_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'razorpay_secret'], ['value' => 'rzp_sec_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'paystack_secret'], ['value' => 'ps_sec_' . $this->token]);

        // 1. Stripe
        $capturedStripe = null;
        $stripeMock = $this->getMockBuilder(StripePaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['createStripeCheckoutSession'])
            ->getMock();
        $stripeMock->method('createStripeCheckoutSession')->willReturnCallback(function (array $payload) use (&$capturedStripe) {
            $capturedStripe = $payload;

            return (object) ['id' => 'cs_gen_' . $this->token, 'url' => 'https://checkout.stripe.test/session'];
        });
        $this->app->instance(StripePaymentController::class, $stripeMock);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/stripe/generate-session', ['plan_id' => $this->paidPlan->id, 'amount' => 1])
            ->assertStatus(200);
        $this->assertSame(9900, $capturedStripe['line_items'][0]['price_data']['unit_amount']);
        $this->assertDatabaseHas('payment_intents', [
            'user_id' => $this->adminA->id,
            'gateway' => Subscription::TYPE_STRIPE,
            'provider_reference' => 'cs_gen_' . $this->token,
            'amount_minor' => 9900,
            'status' => PaymentIntent::STATUS_PENDING,
        ]);

        // 2. PayPal (approve link at index 2, NOT hardcoded index 1!)
        $capturedPaypal = null;
        $paypalMock = $this->getMockBuilder(PaypalPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['createPaypalOrder'])
            ->getMock();
        $paypalMock->method('createPaypalOrder')->willReturnCallback(function (array $payload) use (&$capturedPaypal) {
            $capturedPaypal = $payload;

            return [
                'id' => 'PP_ORD_' . $this->token,
                'links' => [
                    ['rel' => 'self', 'href' => 'https://api.paypal.test/self'],
                    ['rel' => 'update', 'href' => 'https://api.paypal.test/update'],
                    ['rel' => 'approve', 'href' => 'https://www.paypal.test/checkoutnow?token=PP_ORD'],
                ],
            ];
        });
        $this->app->instance(PaypalPaymentController::class, $paypalMock);

        $ppResp = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/paypal/generate-session', ['plan_id' => $this->paidPlan->id, 'amount' => 1])
            ->assertStatus(200);
        $this->assertSame('99.00', $capturedPaypal['purchase_units'][0]['amount']['value']);
        $this->assertSame('https://www.paypal.test/checkoutnow?token=PP_ORD', $ppResp->json('data.url'));
        $this->assertDatabaseHas('payment_intents', [
            'user_id' => $this->adminA->id,
            'gateway' => Subscription::TYPE_PAYPAL,
            'provider_reference' => 'PP_ORD_' . $this->token,
            'amount_minor' => 9900,
        ]);

        // 3. Razorpay
        $capturedRzp = null;
        $rzpMock = $this->getMockBuilder(RazorpayPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['createRazorpayOrder'])
            ->getMock();
        $rzpMock->method('createRazorpayOrder')->willReturnCallback(function ($k, $s, array $orderData) use (&$capturedRzp) {
            $capturedRzp = $orderData;

            return (object) ['id' => 'order_gen_' . $this->token];
        });
        $this->app->instance(RazorpayPaymentController::class, $rzpMock);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/razorpay/generate-session', ['plan_id' => $this->paidPlan->id, 'amount' => 1])
            ->assertStatus(200);
        $this->assertSame(9900, $capturedRzp['amount']);
        $this->assertDatabaseHas('payment_intents', [
            'user_id' => $this->adminA->id,
            'gateway' => Subscription::TYPE_RAZORPAY,
            'provider_reference' => 'order_gen_' . $this->token,
            'amount_minor' => 9900,
        ]);

        // 4. Paystack + sanitized error handling (raw exception never returned)
        $capturedPs = null;
        $throwPaystack = false;
        $psMock = $this->getMockBuilder(PaystackPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['initializePaystackTransaction'])
            ->getMock();
        $psMock->method('initializePaystackTransaction')->willReturnCallback(function ($s, array $payload) use (&$capturedPs, &$throwPaystack) {
            if ($throwPaystack) {
                throw new \RuntimeException('INTERNAL_SECRET_KEY_LEAK_XYZ');
            }
            $capturedPs = $payload;

            return (object) ['data' => (object) ['authorization_url' => 'https://checkout.paystack.test/auth']];
        });
        $this->app->instance(PaystackPaymentController::class, $psMock);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/paystack/generate-session', ['plan_id' => $this->paidPlan->id, 'amount' => 1])
            ->assertStatus(200);
        $this->assertSame(9900, $capturedPs['amount']);

        $throwPaystack = true;
        $errResp = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/paystack/generate-session', ['plan_id' => $this->paidPlan->id])
            ->assertStatus(422);
        $this->assertStringNotContainsString('INTERNAL_SECRET_KEY_LEAK_XYZ', $errResp->getContent());
    }

    // -------------------------------------------------------------------------
    // Task 6: PayPal full callback matrix (10 scenarios including exact cents & expiry)
    // -------------------------------------------------------------------------

    public function test_paypal_callback_full_security_matrix(): void
    {
        $orderId = 'PP_MAT_' . $this->token;
        $captureId = 'CAP_MAT_' . $this->token;
        $intent = $this->createIntent(Subscription::TYPE_PAYPAL, $orderId);

        $nextCapture = null;
        $mock = $this->getMockBuilder(PaypalPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['capturePaypalOrder'])
            ->getMock();
        $mock->method('capturePaypalOrder')->willReturnCallback(function () use (&$nextCapture) {
            return $nextCapture;
        });
        $this->app->instance(PaypalPaymentController::class, $mock);

        // 1. Missing capture resource rejected even if order status is COMPLETED
        $nextCapture = ['id' => $orderId, 'status' => 'COMPLETED', 'purchase_units' => [['reference_id' => $intent->reference]]];
        $this->get('/paypal/paypal-success?token=' . $orderId)->assertRedirect('/app/user/payment-failed?status=false');

        // 2. 1-cent amount tampering (98.99 vs 99.00) rejected (no 0.01 float tolerance!)
        $nextCapture = [
            'id' => $orderId,
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => $intent->reference,
                'payments' => ['captures' => [['id' => $captureId, 'status' => 'COMPLETED', 'amount' => ['value' => '98.99', 'currency_code' => 'USD']]]],
            ]],
        ];
        $this->get('/paypal/paypal-success?token=' . $orderId)->assertRedirect('/app/user/payment-failed?status=false');

        // 3. Currency mismatch rejected
        $nextCapture['purchase_units'][0]['payments']['captures'][0]['amount'] = ['value' => '99.00', 'currency_code' => 'EUR'];
        $this->get('/paypal/paypal-success?token=' . $orderId)->assertRedirect('/app/user/payment-failed?status=false');

        // 4. Unknown provider reference (no server intent) rejected
        $nextCapture = [
            'id' => 'PP_UNKNOWN_' . $this->token,
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => 'pi_nonexistent',
                'payments' => ['captures' => [['id' => 'CAP_UNK', 'status' => 'COMPLETED', 'amount' => ['value' => '99.00', 'currency_code' => 'USD']]]],
            ]],
        ];
        $this->get('/paypal/paypal-success?token=PP_UNKNOWN_' . $this->token)->assertRedirect('/app/user/payment-failed?status=false');

        // 5. Expired intent rejected
        $expiredOrderId = 'PP_EXP_' . $this->token;
        $expiredIntent = $this->createIntent(Subscription::TYPE_PAYPAL, $expiredOrderId, ['expires_at' => Carbon::now()->subMinute()]);
        $nextCapture = [
            'id' => $expiredOrderId,
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => $expiredIntent->reference,
                'payments' => ['captures' => [['id' => 'CAP_EXP_' . $this->token, 'status' => 'COMPLETED', 'amount' => ['value' => '99.00', 'currency_code' => 'USD']]]],
            ]],
        ];
        $this->get('/paypal/paypal-success?token=' . $expiredOrderId)->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertSame(PaymentIntent::STATUS_EXPIRED, $expiredIntent->fresh()->status);

        // 6. Valid COMPLETED capture activates subscription
        $nextCapture = [
            'id' => $orderId,
            'status' => 'COMPLETED',
            'purchase_units' => [[
                'reference_id' => $intent->reference,
                'payments' => ['captures' => [['id' => $captureId, 'status' => 'COMPLETED', 'amount' => ['value' => '99.00', 'currency_code' => 'USD']]]],
            ]],
        ];
        $this->get('/paypal/paypal-success?token=' . $orderId)->assertRedirect('/app/user/payment-success?status=true');
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('status', Subscription::ACTIVE)->get());
        $this->assertSame(PaymentIntent::STATUS_PAID, $intent->fresh()->status);

        // 7. Duplicate/replay callback is idempotent (still 1 subscription, 1 transaction)
        $this->get('/paypal/paypal-success?token=' . $orderId)->assertRedirect('/app/user/payment-success?status=true');
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->get());
        $this->assertCount(1, Transaction::withoutGlobalScope('tenant')->where('transaction_id', $captureId)->get());
    }

    // -------------------------------------------------------------------------
    // Task 3: Razorpay hardening (order notes vs payment notes, order amount, expiry, replay)
    // -------------------------------------------------------------------------

    public function test_razorpay_hardening_uses_order_notes_and_enforces_full_security_matrix(): void
    {
        // Verify config('payment.razorpay.*') fallback works when SadminSetting is empty
        SadminSetting::whereIn('key', ['razorpay_key', 'razorpay_secret'])->delete();
        $secret = 'rzp_cfg_secret_' . $this->token;
        config(['payment.razorpay.key' => 'rzp_cfg_key', 'payment.razorpay.secret' => $secret]);

        $orderId = 'order_mat_' . $this->token;
        $paymentId = 'pay_mat_' . $this->token;
        $sig = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
        $intent = $this->createIntent(Subscription::TYPE_RAZORPAY, $orderId);

        $nextPair = null;
        $mock = $this->getMockBuilder(RazorpayPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['fetchRazorpayPaymentAndOrder'])
            ->getMock();
        $mock->method('fetchRazorpayPaymentAndOrder')->willReturnCallback(function () use (&$nextPair) {
            return $nextPair;
        });
        $this->app->instance(RazorpayPaymentController::class, $mock);

        // 1. Order/payment amount mismatch rejected
        $nextPair = [
            ['id' => $paymentId, 'order_id' => $orderId, 'status' => 'captured', 'amount' => 100, 'currency' => 'USD'],
            ['id' => $orderId, 'amount' => 9900, 'currency' => 'USD', 'notes' => ['intent_ref' => $intent->reference, 'user_id' => $this->adminA->id, 'plan_id' => $this->paidPlan->id]],
        ];
        $this->post('/razorpay/success', ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $sig])
            ->assertRedirect('/app/user/payment-failed?status=false');

        // 2. Wrong user in order notes rejected
        $nextPair = [
            ['id' => $paymentId, 'order_id' => $orderId, 'status' => 'captured', 'amount' => 9900, 'currency' => 'USD'],
            ['id' => $orderId, 'amount' => 9900, 'currency' => 'USD', 'notes' => ['intent_ref' => $intent->reference, 'user_id' => $this->adminB->id, 'plan_id' => $this->paidPlan->id]],
        ];
        $this->post('/razorpay/success', ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $sig])
            ->assertRedirect('/app/user/payment-failed?status=false');

        // 3. Wrong plan in order notes rejected
        $nextPair[1]['notes'] = ['intent_ref' => $intent->reference, 'user_id' => $this->adminA->id, 'plan_id' => $this->altPlan->id];
        $this->post('/razorpay/success', ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $sig])
            ->assertRedirect('/app/user/payment-failed?status=false');

        // 4. Non-captured status ('authorized') rejected
        $nextPair = [
            ['id' => $paymentId, 'order_id' => $orderId, 'status' => 'authorized', 'amount' => 9900, 'currency' => 'USD'],
            ['id' => $orderId, 'amount' => 9900, 'currency' => 'USD', 'notes' => ['intent_ref' => $intent->reference, 'user_id' => $this->adminA->id, 'plan_id' => $this->paidPlan->id]],
        ];
        $this->post('/razorpay/success', ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $sig])
            ->assertRedirect('/app/user/payment-failed?status=false');

        // 5. Untrusted tampered payment.notes ignored when order.notes are valid -> activates adminA (NOT adminB!)
        $nextPair = [
            [
                'id' => $paymentId,
                'order_id' => $orderId,
                'status' => 'captured',
                'amount' => 9900,
                'currency' => 'USD',
                'notes' => ['user_id' => $this->adminB->id, 'plan_id' => $this->altPlan->id], // untrusted payment notes
            ],
            [
                'id' => $orderId,
                'amount' => 9900,
                'currency' => 'USD',
                'notes' => ['intent_ref' => $intent->reference, 'user_id' => $this->adminA->id, 'plan_id' => $this->paidPlan->id],
            ],
        ];
        $this->post('/razorpay/success', ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $sig])
            ->assertRedirect('/app/user/payment-success?status=true');

        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('plan_id', $this->paidPlan->id)->where('status', Subscription::ACTIVE)->get());
        $this->assertCount(0, Subscription::where('user_id', $this->adminB->id)->get());

        // 6. Replay is idempotent
        $this->post('/razorpay/success', ['razorpay_order_id' => $orderId, 'razorpay_payment_id' => $paymentId, 'razorpay_signature' => $sig])
            ->assertRedirect('/app/user/payment-success?status=true');
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->get());
    }

    // -------------------------------------------------------------------------
    // Task 4: Paystack config fallback & full callback security matrix
    // -------------------------------------------------------------------------

    public function test_paystack_callback_full_security_matrix_and_config_fallback(): void
    {
        // Verify config('services.paystack.secret') fallback works when SadminSetting is empty
        SadminSetting::whereIn('key', ['paystack_key', 'paystack_secret'])->delete();
        config(['services.paystack.secret' => 'ps_cfg_secret_' . $this->token]);

        $ref = 'pi_ps_' . $this->token;
        $intent = $this->createIntent(Subscription::TYPE_PAYSTACK, $ref, ['reference' => $ref]);

        $nextVerify = null;
        $mock = $this->getMockBuilder(PaystackPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['verifyPaystackTransaction'])
            ->getMock();
        $mock->method('verifyPaystackTransaction')->willReturnCallback(function () use (&$nextVerify) {
            return $nextVerify;
        });
        $this->app->instance(PaystackPaymentController::class, $mock);

        // 1. Reference mismatch rejected
        $nextVerify = (object) [
            'status' => true,
            'data' => (object) ['id' => 'ps_tx_1_' . $this->token, 'reference' => 'different_ref', 'status' => 'success', 'amount' => 9900, 'currency' => 'USD', 'metadata' => ['intent_ref' => $ref]],
        ];
        $this->get('/paystack/payment-success?reference=' . $ref)->assertRedirect('/app/user/payment-failed?status=false');

        // 2. Amount tampered rejected
        $nextVerify = (object) [
            'status' => true,
            'data' => (object) ['id' => 'ps_tx_2_' . $this->token, 'reference' => $ref, 'status' => 'success', 'amount' => 500, 'currency' => 'USD', 'metadata' => ['intent_ref' => $ref]],
        ];
        $this->get('/paystack/payment-success?reference=' . $ref)->assertRedirect('/app/user/payment-failed?status=false');

        // 3. Currency mismatch rejected
        $nextVerify->data->amount = 9900;
        $nextVerify->data->currency = 'NGN';
        $this->get('/paystack/payment-success?reference=' . $ref)->assertRedirect('/app/user/payment-failed?status=false');

        // 4. Wrong user rejected
        $nextVerify->data->currency = 'USD';
        $nextVerify->data->metadata = ['intent_ref' => $ref, 'user_id' => $this->adminB->id, 'plan_id' => $this->paidPlan->id];
        $this->get('/paystack/payment-success?reference=' . $ref)->assertRedirect('/app/user/payment-failed?status=false');

        // 5. Valid success activates subscription
        $txId = 'ps_tx_ok_' . $this->token;
        $nextVerify = (object) [
            'status' => true,
            'data' => (object) [
                'id' => $txId,
                'reference' => $ref,
                'status' => 'success',
                'amount' => 9900,
                'currency' => 'USD',
                'metadata' => ['intent_ref' => $ref, 'user_id' => $this->adminA->id, 'plan_id' => $this->paidPlan->id],
            ],
        ];
        $this->get('/paystack/payment-success?reference=' . $ref)->assertRedirect('/app/user/payment-success?status=true');
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('status', Subscription::ACTIVE)->get());

        // 6. Duplicate/replay callback is idempotent
        $this->get('/paystack/payment-success?reference=' . $ref)->assertRedirect('/app/user/payment-success?status=true');
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->get());
        $this->assertCount(1, Transaction::withoutGlobalScope('tenant')->where('transaction_id', $txId)->get());
    }

    // -------------------------------------------------------------------------
    // Task 7: Signed webhooks for all 4 gateways (valid, invalid sig, duplicate, out-of-order)
    // -------------------------------------------------------------------------

    public function test_stripe_signed_webhook_verification_idempotency_and_out_of_order_safety(): void
    {
        $whSecret = 'whsec_test_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'stripe_webhook_secret'], ['value' => $whSecret]);

        $sessionId = 'cs_wh_' . $this->token;
        $intent = $this->createIntent(Subscription::TYPE_STRIPE, $sessionId);

        $payload = json_encode([
            'id' => 'evt_stripe_' . $this->token,
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

        // 1. Invalid signature rejected (400)
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't=' . time() . ',v1=deadbeef',
        ], $payload)->assertStatus(400);
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());

        // 2. Valid Stripe HMAC-SHA256 signature activates subscription
        $ts = time();
        $v1 = hash_hmac('sha256', "{$ts}.{$payload}", $whSecret);
        $sigHeader = "t={$ts},v1={$v1}";

        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sigHeader,
        ], $payload)->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('status', Subscription::ACTIVE)->get());

        // 3. Duplicate webhook event deduplicated (200 duplicate_ignored, no second subscription)
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $sigHeader,
        ], $payload)->assertStatus(200)->assertJson(['status' => 'duplicate_ignored']);
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->get());

        // 4. Out-of-order expired event NEVER downgrades an already PAID intent
        $expiredPayload = json_encode([
            'id' => 'evt_stripe_exp_' . $this->token,
            'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => $sessionId]],
        ]);
        $v1Exp = hash_hmac('sha256', "{$ts}.{$expiredPayload}", $whSecret);
        $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$ts},v1={$v1Exp}",
        ], $expiredPayload)->assertStatus(200);
        $this->assertSame(PaymentIntent::STATUS_PAID, $intent->fresh()->status);
    }

    public function test_razorpay_and_paystack_and_paypal_signed_webhooks(): void
    {
        // --- A. Razorpay Webhook ---
        $rzpWhSecret = 'rzp_wh_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'razorpay_webhook_secret'], ['value' => $rzpWhSecret]);
        $rzpOrderId = 'order_wh_' . $this->token;
        $rzpIntent = $this->createIntent(Subscription::TYPE_RAZORPAY, $rzpOrderId);

        $rzpBody = json_encode([
            'event' => 'payment.captured',
            'payload' => [
                'payment' => ['entity' => ['id' => 'pay_wh_' . $this->token, 'order_id' => $rzpOrderId, 'status' => 'captured', 'amount' => 9900, 'currency' => 'USD']],
                'order' => ['entity' => ['id' => $rzpOrderId, 'amount' => 9900, 'currency' => 'USD', 'notes' => ['intent_ref' => $rzpIntent->reference, 'user_id' => $this->adminA->id, 'plan_id' => $this->paidPlan->id]]],
            ],
        ]);
        $rzpSig = hash_hmac('sha256', $rzpBody, $rzpWhSecret);

        // Invalid signature rejected
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => 'bad_sig',
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_rzp_' . $this->token,
        ], $rzpBody)->assertStatus(400);

        // Valid signature settles
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $rzpSig,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_rzp_' . $this->token,
        ], $rzpBody)->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertSame(PaymentIntent::STATUS_PAID, $rzpIntent->fresh()->status);

        // Duplicate event ignored
        $this->call('POST', '/api/webhooks/razorpay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $rzpSig,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_rzp_' . $this->token,
        ], $rzpBody)->assertStatus(200)->assertJson(['status' => 'duplicate_ignored']);

        // --- B. Paystack Webhook (HMAC-SHA512) ---
        $psSecret = 'ps_wh_sec_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'paystack_secret'], ['value' => $psSecret]);
        $psRef = 'pi_ps_wh_' . $this->token;
        $psIntent = $this->createIntent(Subscription::TYPE_PAYSTACK, $psRef, ['reference' => $psRef, 'user_id' => $this->adminB->id, 'tenant_id' => $this->tenantBId]);

        $psBody = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 'ps_evt_tx_' . $this->token,
                'reference' => $psRef,
                'status' => 'success',
                'amount' => 9900,
                'currency' => 'USD',
                'metadata' => ['intent_ref' => $psRef, 'user_id' => $this->adminB->id, 'plan_id' => $this->paidPlan->id],
            ],
        ]);
        $psSig = hash_hmac('sha512', $psBody, $psSecret);

        $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => 'wrong_sha512',
        ], $psBody)->assertStatus(400);

        $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $psSig,
        ], $psBody)->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertSame(PaymentIntent::STATUS_PAID, $psIntent->fresh()->status);
        $this->assertCount(1, Subscription::where('user_id', $this->adminB->id)->where('status', Subscription::ACTIVE)->get());

        // Duplicate ignored
        $this->call('POST', '/api/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $psSig,
        ], $psBody)->assertStatus(200)->assertJson(['status' => 'duplicate_ignored']);

        // --- C. PayPal Webhook (official verify-webhook-signature API boundary) ---
        SadminSetting::updateOrCreate(['key' => 'paypal_webhook_id'], ['value' => 'WH_PP_' . $this->token]);
        $ppOrderId = 'PP_WH_ORD_' . $this->token;
        $ppIntent = $this->createIntent(Subscription::TYPE_PAYPAL, $ppOrderId);

        $verifyResult = false;
        $whMock = $this->getMockBuilder(PaymentWebhookController::class)
            ->setConstructorArgs([app(PaymentIntentService::class)])
            ->onlyMethods(['verifyPaypalWebhookSignature'])
            ->getMock();
        $whMock->method('verifyPaypalWebhookSignature')->willReturnCallback(function () use (&$verifyResult) {
            return $verifyResult;
        });
        $this->app->instance(PaymentWebhookController::class, $whMock);

        $ppBody = json_encode([
            'id' => 'WH-EVT-PP-' . $this->token,
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'resource' => [
                'id' => 'CAP_WH_' . $this->token,
                'status' => 'COMPLETED',
                'custom_id' => $ppIntent->reference,
                'amount' => ['value' => '99.00', 'currency_code' => 'USD'],
                'supplementary_data' => ['related_ids' => ['order_id' => $ppOrderId]],
            ],
        ]);
        $ppHeaders = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_ID' => 'tr_' . $this->token,
            'HTTP_PAYPAL_TRANSMISSION_TIME' => now()->toIso8601String(),
            'HTTP_PAYPAL_CERT_URL' => 'https://api.sandbox.paypal.com/cert.pem',
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => 'sig_test',
        ];

        // Invalid verification rejected
        $verifyResult = false;
        $this->call('POST', '/api/webhooks/paypal', [], [], [], $ppHeaders, $ppBody)->assertStatus(400);

        // Valid verification settles
        $verifyResult = true;
        $this->call('POST', '/api/webhooks/paypal', [], [], [], $ppHeaders, $ppBody)->assertStatus(200)->assertJson(['status' => 'processed']);
        $this->assertSame(PaymentIntent::STATUS_PAID, $ppIntent->fresh()->status);

        // Duplicate ignored
        $this->call('POST', '/api/webhooks/paypal', [], [], [], $ppHeaders, $ppBody)->assertStatus(200)->assertJson(['status' => 'duplicate_ignored']);
    }
}
