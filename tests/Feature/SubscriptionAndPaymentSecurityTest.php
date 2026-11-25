<?php

namespace Tests\Feature;

use App\Http\Controllers\PaypalPaymentController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\RazorpayPaymentController;
use App\Http\Controllers\StripePaymentController;
use App\Models\Currency;
use App\Models\PaymentIntent;
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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SubscriptionAndPaymentSecurityTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $freePlan = null;
    private ?Plan $paidPlan = null;
    private ?Plan $unlimitedPlan = null;
    private ?Currency $currency = null;

    private array $originalSadminSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'sub_tA_' . $this->token;
        $this->tenantBId = 'sub_tB_' . $this->token;

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'store_id' => "sub_store_a_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
            [
                'id' => $this->tenantBId,
                'store_id' => "sub_store_b_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
        ]);

        $this->adminA = $this->createAdmin("SubAdminA_{$this->token}", "sub.a.{$this->token}@stockmate.test", $this->tenantAId);
        $this->adminB = $this->createAdmin("SubAdminB_{$this->token}", "sub.b.{$this->token}@stockmate.test", $this->tenantBId);

        $this->storeA = Store::create([
            'name' => "Sub Store A {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);
        $this->storeB = Store::create([
            'name' => "Sub Store B {$this->token}",
            'tenant_id' => $this->tenantBId,
            'user_id' => $this->adminB->id,
            'status' => 1,
        ]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $this->currency = Currency::where('code', 'USD')->first() ?: Currency::first();

        $this->freePlan = Plan::create([
            'name' => "Free Plan {$this->token}",
            'price' => 0,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => 1,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        $this->paidPlan = Plan::create([
            'name' => "Pro Plan {$this->token}",
            'price' => 99.00,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => 3,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        $this->unlimitedPlan = Plan::create([
            'name' => "Enterprise Plan {$this->token}",
            'price' => 299.00,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $this->currency->id,
            'no_of_stores' => -1,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        foreach (['stripe_secret', 'razorpay_key', 'razorpay_secret', 'paystack_secret', 'paypal_client_id', 'paypal_secret'] as $k) {
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

        $userIds = array_filter([$this->adminA?->id, $this->adminB?->id]);
        if (! empty($userIds)) {
            PaymentIntent::whereIn('user_id', $userIds)->delete();
            Subscription::whereIn('user_id', $userIds)->delete();
            Transaction::withoutGlobalScope('tenant')->whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
            User::withoutGlobalScope('tenant')->whereIn('id', $userIds)->forceDelete();
        }

        Setting::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();

        $storeIds = array_filter([$this->storeA?->id, $this->storeB?->id]);
        if (! empty($storeIds)) {
            Store::whereIn('id', $storeIds)->delete();
        }

        $planIds = array_filter([$this->freePlan?->id, $this->paidPlan?->id, $this->unlimitedPlan?->id]);
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
            'phone' => '92307' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $user->assignRole(Role::ADMIN);

        return $user;
    }

    // -------------------------------------------------------------------------
    // 1. Free plan & self-activation security
    // -------------------------------------------------------------------------

    public function test_free_plan_activation_works_and_duplicate_is_idempotent(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/subscription', [
                'plan_id' => $this->freePlan->id,
                'payment_type' => Subscription::TYPE_FREE,
            ])
            ->assertStatus(200);

        $subs = Subscription::where('user_id', $this->adminA->id)->get();
        $this->assertCount(1, $subs);
        $this->assertEquals(Subscription::ACTIVE, (int) $subs->first()->status);
        $this->assertEquals(0.0, (float) $subs->first()->payable_amount);

        // Duplicate Free activation on same plan is idempotent (still 1 row)
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/subscription', [
                'plan_id' => $this->freePlan->id,
                'payment_type' => Subscription::TYPE_FREE,
            ])
            ->assertStatus(200);

        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->get());
    }

    public function test_tenant_cannot_activate_paid_plan_for_free_or_manipulate_status_or_user_id(): void
    {
        // 1. Paid plan via TYPE_FREE -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/subscription', [
                'plan_id' => $this->paidPlan->id,
                'payment_type' => Subscription::TYPE_FREE,
            ])
            ->assertStatus(422);
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());

        // 2. Manual payment with spoofed status=ACTIVE, is_paid=1, user_id=adminB -> forced PENDING for adminA!
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/subscription', [
                'plan_id' => $this->paidPlan->id,
                'payment_type' => Subscription::TYPE_MANUAL,
                'status' => Subscription::ACTIVE,
                'is_paid' => 1,
                'user_id' => $this->adminB->id,
                'payable_amount' => 0,
            ])
            ->assertStatus(200);

        $this->assertCount(0, Subscription::where('user_id', $this->adminB->id)->get());
        $sub = Subscription::where('user_id', $this->adminA->id)->first();
        $this->assertNotNull($sub);
        $this->assertEquals(Subscription::PENDING, (int) $sub->status);
        $this->assertEquals(0, (int) $sub->is_paid);
        $this->assertEquals(99.00, (float) $sub->payable_amount);

        // 3. Direct gateway payment_type submitted to /api/subscription -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/subscription', [
                'plan_id' => $this->paidPlan->id,
                'payment_type' => Subscription::TYPE_STRIPE,
            ])
            ->assertStatus(422);

        // 4. Non-existent plan_id -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/subscription', [
                'plan_id' => 99999999,
                'payment_type' => Subscription::TYPE_MANUAL,
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // 2. Server-side pricing & amount validation
    // -------------------------------------------------------------------------

    public function test_server_side_pricing_ignores_client_amount_and_rejects_malformed_values(): void
    {
        $pricing = SubscriptionRepository::calculatePayableAmount($this->paidPlan, $this->adminA->id);
        $this->assertEquals(99.00, $pricing['plan_price']);
        $this->assertEquals(99.00, $pricing['payable_amount']);
        $this->assertEquals(strtoupper($this->currency->code), $pricing['currency_code']);

        // Negative or zero amount on Stripe/PayPal/Razorpay/Paystack generateSession -> 422
        foreach (['stripe', 'paypal', 'razorpay', 'paystack'] as $gw) {
            $this->actingAs($this->adminA, 'sanctum')
                ->postJson("/api/{$gw}/generate-session", [
                    'plan_id' => $this->paidPlan->id,
                    'amount' => -50,
                ])
                ->assertStatus(422);

            $this->actingAs($this->adminA, 'sanctum')
                ->postJson("/api/{$gw}/generate-session", [
                    'plan_id' => $this->paidPlan->id,
                    'amount' => 'not-a-number',
                ])
                ->assertStatus(422);
        }

        // Free plan ($0) rejected for paid Stripe checkout -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/stripe/generate-session', [
                'plan_id' => $this->freePlan->id,
            ])
            ->assertStatus(422);
    }

    // -------------------------------------------------------------------------
    // 3. Payment gateway verification (Stripe, PayPal, Razorpay, Paystack)
    // -------------------------------------------------------------------------

    public function test_stripe_callback_verifies_paid_status_amount_currency_and_replay_idempotency(): void
    {
        $validId = 'cs_valid_' . $this->token;
        $intentA = PaymentIntent::create([
            'reference' => 'pi_st_' . $this->token,
            'user_id' => $this->adminA->id,
            'tenant_id' => $this->tenantAId,
            'plan_id' => $this->paidPlan->id,
            'gateway' => Subscription::TYPE_STRIPE,
            'amount_minor' => 9900,
            'amount_decimal' => 99.00,
            'currency' => strtoupper($this->currency->code),
            'provider_reference' => $validId,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => now()->addHour(),
        ]);

        // Bind ONE mocked StripePaymentController before the first request (Laravel caches Route::$controller)
        $nextSession = null;
        $mock = $this->getMockBuilder(StripePaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['retrieveStripeSession'])
            ->getMock();
        $mock->method('retrieveStripeSession')->willReturnCallback(function () use (&$nextSession) {
            return $nextSession;
        });
        $this->app->instance(StripePaymentController::class, $mock);

        $bindMock = function (object $fakeSession) use (&$nextSession) {
            $nextSession = $fakeSession;
        };

        $validMeta = (object) [
            'intent_ref' => $intentA->reference,
            'user_id' => (string) $this->adminA->id,
            'plan_id' => (string) $this->paidPlan->id,
        ];

        // A. Unpaid session does NOT activate
        $bindMock((object) [
            'id' => $validId,
            'payment_status' => 'unpaid',
            'status' => 'open',
            'amount_total' => 9900,
            'currency' => strtolower($this->currency->code),
            'client_reference_id' => $intentA->reference,
            'metadata' => $validMeta,
        ]);
        $this->get('/stripe/payment-success?session_id=' . $validId)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());

        // B. Tampered amount ($1 instead of $99) rejected
        $bindMock((object) [
            'id' => $validId,
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 100,
            'currency' => strtolower($this->currency->code),
            'client_reference_id' => $intentA->reference,
            'metadata' => $validMeta,
        ]);
        $this->get('/stripe/payment-success?session_id=' . $validId)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());

        // C. Currency mismatch rejected
        $bindMock((object) [
            'id' => $validId,
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 9900,
            'currency' => 'eur',
            'client_reference_id' => $intentA->reference,
            'metadata' => $validMeta,
        ]);
        $this->get('/stripe/payment-success?session_id=' . $validId)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());

        // D. Valid verified payment activates subscription
        $validSession = (object) [
            'id' => $validId,
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 9900,
            'currency' => strtolower($this->currency->code),
            'client_reference_id' => $intentA->reference,
            'metadata' => $validMeta,
        ];
        $bindMock($validSession);
        $this->get('/stripe/payment-success?session_id=' . $validId)
            ->assertRedirect('/app/user/payment-success?status=true');

        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('status', Subscription::ACTIVE)->get());
        $this->assertCount(1, Transaction::withoutGlobalScope('tenant')->where('transaction_id', $validId)->get());

        // E. Replay of same successful callback is idempotent (no duplicate subscription or transaction)
        $bindMock($validSession);
        $this->get('/stripe/payment-success?session_id=' . $validId)
            ->assertRedirect('/app/user/payment-success?status=true');
        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->get());
        $this->assertCount(1, Transaction::withoutGlobalScope('tenant')->where('transaction_id', $validId)->get());

        // F. Same transaction ID cannot activate Tenant B's subscription
        $bindMock((object) [
            'id' => $validId,
            'payment_status' => 'paid',
            'status' => 'complete',
            'amount_total' => 9900,
            'currency' => strtolower($this->currency->code),
            'client_reference_id' => $intentA->reference,
            'metadata' => (object) [
                'intent_ref' => $intentA->reference,
                'user_id' => (string) $this->adminB->id,
                'plan_id' => (string) $this->paidPlan->id,
            ],
        ]);
        $this->get('/stripe/payment-success?session_id=' . $validId)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertCount(0, Subscription::where('user_id', $this->adminB->id)->get());

        // G. Failed/cancelled callback never activates
        $this->get('/stripe/failed/')->assertRedirect('/app/user/payment-failed?status=false');
    }

    public function test_razorpay_callback_rejects_invalid_signature_and_accepts_valid_hmac(): void
    {
        $secret = 'rzp_test_secret_' . $this->token;
        SadminSetting::updateOrCreate(['key' => 'razorpay_key'], ['value' => 'rzp_test_key']);
        SadminSetting::updateOrCreate(['key' => 'razorpay_secret'], ['value' => $secret]);

        $orderId = 'order_' . $this->token;
        $paymentId = 'pay_' . $this->token;

        $intentA = PaymentIntent::create([
            'reference' => 'pi_rzp_' . $this->token,
            'user_id' => $this->adminA->id,
            'tenant_id' => $this->tenantAId,
            'plan_id' => $this->paidPlan->id,
            'gateway' => Subscription::TYPE_RAZORPAY,
            'amount_minor' => 9900,
            'amount_decimal' => 99.00,
            'currency' => strtoupper($this->currency->code),
            'provider_reference' => $orderId,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => now()->addHour(),
        ]);

        // Bind mocked RazorpayPaymentController BEFORE first request (Laravel caches Route::$controller)
        $mock = $this->getMockBuilder(RazorpayPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['fetchRazorpayPaymentAndOrder'])
            ->getMock();
        $mock->method('fetchRazorpayPaymentAndOrder')->willReturn([
            [
                'id' => $paymentId,
                'order_id' => $orderId,
                'status' => 'captured',
                'amount' => 9900,
                'currency' => strtoupper($this->currency->code),
            ],
            [
                'id' => $orderId,
                'receipt' => $intentA->reference,
                'status' => 'paid',
                'amount' => 9900,
                'currency' => strtoupper($this->currency->code),
                'notes' => [
                    'intent_ref' => $intentA->reference,
                    'user_id' => (string) $this->adminA->id,
                    'plan_id' => (string) $this->paidPlan->id,
                ],
            ],
        ]);
        $this->app->instance(RazorpayPaymentController::class, $mock);

        // A. Invalid signature rejected immediately without activating
        $this->post('/razorpay/success', [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => 'invalid_signature',
        ])->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());

        // B. Valid HMAC signature + captured verified payment activates subscription
        $validSig = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
        $this->post('/razorpay/success', [
            'razorpay_order_id' => $orderId,
            'razorpay_payment_id' => $paymentId,
            'razorpay_signature' => $validSig,
        ])->assertRedirect('/app/user/payment-success?status=true');

        $this->assertCount(1, Subscription::where('user_id', $this->adminA->id)->where('status', Subscription::ACTIVE)->get());
    }

    public function test_paypal_and_paystack_reject_incomplete_or_mismatched_callbacks(): void
    {
        // PayPal incomplete status rejected
        $ppMock = $this->getMockBuilder(PaypalPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['capturePaypalOrder'])
            ->getMock();
        $ppMock->method('capturePaypalOrder')->willReturn([
            'id' => 'PP_' . $this->token,
            'status' => 'PENDING',
            'purchase_units' => [],
        ]);
        $this->app->instance(PaypalPaymentController::class, $ppMock);

        $this->get('/paypal/paypal-success?token=tok_' . $this->token)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());

        // Paystack failed status rejected
        SadminSetting::updateOrCreate(['key' => 'paystack_secret'], ['value' => 'sk_test_' . $this->token]);
        $psMock = $this->getMockBuilder(PaystackPaymentController::class)
            ->setConstructorArgs([app(SubscriptionRepository::class)])
            ->onlyMethods(['verifyPaystackTransaction'])
            ->getMock();
        $psMock->method('verifyPaystackTransaction')->willReturn((object) [
            'status' => true,
            'data' => (object) ['id' => 'ps_' . $this->token, 'status' => 'failed', 'amount' => 9900, 'currency' => 'USD', 'metadata' => []],
        ]);
        $this->app->instance(PaystackPaymentController::class, $psMock);

        $this->get('/paystack/payment-success?reference=ref_' . $this->token)
            ->assertRedirect('/app/user/payment-failed?status=false');
        $this->assertCount(0, Subscription::where('user_id', $this->adminA->id)->get());
    }

    // -------------------------------------------------------------------------
    // 4. Tenant isolation, Super Admin approval & unlimited plan (-1) fix
    // -------------------------------------------------------------------------

    public function test_tenant_isolation_and_super_admin_unlimited_plan_approval(): void
    {
        $subB = Subscription::create([
            'user_id' => $this->adminB->id,
            'plan_id' => $this->unlimitedPlan->id,
            'payment_type' => Subscription::TYPE_MANUAL,
            'plan_amount' => 299,
            'payable_amount' => 299,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::PENDING,
        ]);

        // Tenant A cannot list Tenant B's subscription
        $listA = $this->actingAs($this->adminA, 'sanctum')->getJson('/api/subscriptions')->assertStatus(200);
        $this->assertNotContains($subB->id, collect($listA->json('data'))->pluck('id')->all());

        // Tenant A cannot call Super Admin subscription approval endpoint -> 403
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/sadmin/subscriptions/{$subB->id}/status", ['status' => Subscription::ACTIVE])
            ->assertStatus(403);

        // Super Admin approves unlimited (-1) plan without disabling adminB's active store!
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->firstOrFail();

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson("/api/sadmin/subscriptions/{$subB->id}/status", ['status' => Subscription::ACTIVE])
            ->assertStatus(200);

        $subB->refresh();
        $this->storeB->refresh();
        $this->assertEquals(Subscription::ACTIVE, (int) $subB->status);
        $this->assertEquals(1, (int) $subB->is_paid);
        $this->assertEquals(1, (int) $this->storeB->status);
    }

    // -------------------------------------------------------------------------
    // 5. Expired subscription enforcement (GET + write + allowlist + Super Admin)
    // -------------------------------------------------------------------------

    public function test_expired_subscription_blocks_protected_get_and_writes_but_allows_renewal(): void
    {
        Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->paidPlan->id,
            'plan_amount' => 99,
            'payable_amount' => 99,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => Carbon::now()->subMonths(2),
            'end_date' => Carbon::now()->subDay(),
            'status' => Subscription::ACTIVE,
        ]);

        // Protected GET endpoints MUST return 403
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/products')->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/sales')->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/customers')->assertStatus(403);

        // Protected write endpoints MUST return 403
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/stores', ['name' => 'Blocked Store'])->assertStatus(403);

        // Renewal & essential SPA bootstrap endpoints MUST remain accessible
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/subscriptions')->assertStatus(200);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/plans')->assertStatus(200);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/current-plan')->assertStatus(200);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/config')->assertStatus(200);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/permissions')->assertStatus(200);

        // Super Admin remains unaffected
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->firstOrFail();
        $this->actingAs($superAdmin, 'sanctum')->getJson('/api/sadmin/admin-users')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // 6. Payment configuration secret exposure
    // -------------------------------------------------------------------------

    public function test_payment_secrets_are_never_exposed_in_settings_responses(): void
    {
        SadminSetting::updateOrCreate(['key' => 'stripe_secret'], ['value' => 'sk_live_super_secret_' . $this->token]);
        SadminSetting::updateOrCreate(['key' => 'razorpay_secret'], ['value' => 'rzp_live_super_secret_' . $this->token]);

        // Tenant admin cannot access Super Admin payment settings -> 403
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/sadmin/payment-settings')
            ->assertStatus(403);

        // Super Admin payment settings response masks plaintext secrets
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->firstOrFail();
        $resp = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/sadmin/payment-settings')
            ->assertStatus(200);

        $attrs = $resp->json('data.attributes');
        $this->assertSame('', $attrs['stripe_secret']);
        $this->assertSame('', $attrs['razorpay_secret']);
        $this->assertTrue($attrs['stripe_secret_configured']);
        $this->assertTrue($attrs['razorpay_secret_configured']);
        $this->assertStringNotContainsString('sk_live_super_secret_', $resp->getContent());

        // Tenant settings endpoint strips secrets if present
        $s = new Setting(['key' => 'stripe_secret', 'value' => 'tenant_secret_' . $this->token]);
        $s->tenant_id = $this->tenantAId;
        $s->save();
        Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->paidPlan->id,
            'plan_amount' => 99,
            'payable_amount' => 99,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::ACTIVE,
        ]);
        $tenantSettings = $this->actingAs($this->adminA, 'sanctum')->getJson('/api/settings');
        $this->assertStringNotContainsString('tenant_secret_' . $this->token, $tenantSettings->getContent());
    }
}
