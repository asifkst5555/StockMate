<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use App\Services\SaleIdempotencyService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class PhaseC1Batch4CheckoutTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $plan = null;
    private ?Warehouse $warehouseA = null;
    private ?Warehouse $warehouseB = null;
    private ?Customer $customerA = null;
    private ?Customer $customerB = null;
    private ?Product $productA = null;
    private ?Product $productB = null;
    private ?PaymentMethod $pmA = null;
    private ?PaymentMethod $pmB = null;
    private int $unitId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c1b4_tA_' . $this->token;
        $this->tenantBId = 'c1b4_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c1b4_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c1b4_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C1B4AdminA_{$this->token}", "c1b4.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("C1B4AdminB_{$this->token}", "c1b4.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);

        $this->storeA = Store::create(['name' => "C1B4 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "C1B4 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "C1B4 Plan {$this->token}",
            'price' => 0,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $currency->id,
            'no_of_stores' => 5,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        foreach ([$this->adminA, $this->adminB] as $owner) {
            Subscription::create([
                'user_id' => $owner->id,
                'plan_id' => $this->plan->id,
                'payment_type' => Subscription::TYPE_FREE,
                'plan_amount' => 0,
                'payable_amount' => 0,
                'plan_frequency' => Plan::MONTHLY,
                'start_date' => now()->subDay(),
                'end_date' => now()->addMonth(),
                'status' => Subscription::ACTIVE,
            ]);
        }

        $this->warehouseA = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C1B4 Wh A {$this->token}",
            'phone' => '1111111111',
            'country' => 'US',
            'city' => 'NYC',
            'email' => "c1b4.wha.{$this->token}@stockmate.test",
            'zip_code' => '10001',
        ]);
        $this->warehouseB = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C1B4 Wh B {$this->token}",
            'phone' => '2222222222',
            'country' => 'US',
            'city' => 'LA',
            'email' => "c1b4.whb.{$this->token}@stockmate.test",
            'zip_code' => '90001',
        ]);

        $this->customerA = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C1B4 Cust A {$this->token}",
            'email' => "c1b4.ca.{$this->token}@stockmate.test",
            'phone' => '1112223333',
            'country' => 'US',
            'city' => 'NYC',
            'address' => '123 Main St',
        ]);
        $this->customerB = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C1B4 Cust B {$this->token}",
            'email' => "c1b4.cb.{$this->token}@stockmate.test",
            'phone' => '4445556666',
            'country' => 'US',
            'city' => 'LA',
            'address' => '456 Oak Ave',
        ]);

        $brandA = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantAId, 'name' => "C1B4 Brand A {$this->token}"]);
        $brandB = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantBId, 'name' => "C1B4 Brand B {$this->token}"]);
        $catA = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantAId, 'name' => "C1B4 Cat A {$this->token}"]);
        $catB = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantBId, 'name' => "C1B4 Cat B {$this->token}"]);

        $unit = Unit::first();
        $this->unitId = $unit ? (int) $unit->id : 1;

        $mainA = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C1B4 Main A {$this->token}",
            'code' => "C1B4-MA-{$this->token}",
            'product_unit' => (string) $this->unitId,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);
        $mainB = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C1B4 Main B {$this->token}",
            'code' => "C1B4-MB-{$this->token}",
            'product_unit' => (string) $this->unitId,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);

        $this->productA = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $mainA->id,
            'name' => "C1B4 Product A {$this->token}",
            'code' => "C1B4-PA-{$this->token}",
            'product_code' => "C1B4-SKA-{$this->token}",
            'product_category_id' => $catA->id,
            'brand_id' => $brandA->id,
            'product_cost' => 40.00,
            'product_price' => 80.00,
            'product_unit' => (string) $this->unitId,
            'sale_unit' => (string) $this->unitId,
            'purchase_unit' => (string) $this->unitId,
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);
        $this->productB = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantBId,
            'main_product_id' => $mainB->id,
            'name' => "C1B4 Product B {$this->token}",
            'code' => "C1B4-PB-{$this->token}",
            'product_code' => "C1B4-SKB-{$this->token}",
            'product_category_id' => $catB->id,
            'brand_id' => $brandB->id,
            'product_cost' => 20.00,
            'product_price' => 50.00,
            'product_unit' => (string) $this->unitId,
            'sale_unit' => (string) $this->unitId,
            'purchase_unit' => (string) $this->unitId,
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);

        $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantAId,
            'warehouse_id' => $this->warehouseA->id,
            'product_id' => $this->productA->id,
            'quantity' => 50,
        ]);
        $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantBId,
            'warehouse_id' => $this->warehouseB->id,
            'product_id' => $this->productB->id,
            'quantity' => 50,
        ]);

        $this->pmA = $this->createWithoutTenantScope(PaymentMethod::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "Cash A {$this->token}",
            'status' => 1,
        ]);
        $this->pmB = $this->createWithoutTenantScope(PaymentMethod::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "Cash B {$this->token}",
            'status' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $tenantIds = [$this->tenantAId, $this->tenantBId];
        $saleIds = DB::table('sales')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        if (! empty($saleIds)) {
            DB::table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
            DB::table('sale_items')->whereIn('sale_id', $saleIds)->delete();
            DB::table('sales')->whereIn('id', $saleIds)->delete();
        }
        DB::table('stock_movements')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('manage_stocks')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('products')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('main_products')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('product_categories')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('brands')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('payment_methods')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('customers')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('warehouses')->whereIn('tenant_id', $tenantIds)->delete();

        $userIds = array_filter([$this->adminA?->id, $this->adminB?->id]);
        if (! empty($userIds)) {
            Subscription::whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            Store::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->delete();
            User::whereIn('id', $userIds)->forceDelete();
        }
        if ($this->plan) {
            $this->plan->delete();
        }
        DB::table('tenants')->whereIn('id', $tenantIds)->delete();

        parent::tearDown();
    }

    private function makeUser(string $name, string $email, string $tenantId, string $roleName): User
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => $email,
            'phone' => '92317' . random_int(1000000, 9999999),
            'password' => Hash::make('Password123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $user->assignRole($roleName);

        return $user;
    }

    private function createWithoutTenantScope(string $modelClass, array $attributes)
    {
        return $modelClass::withoutEvents(function () use ($modelClass, $attributes) {
            $instance = new $modelClass();
            $instance->forceFill($attributes);
            $instance->save();
            return $instance;
        });
    }

    private function payloadFor(string $tenant, array $overrides = []): array
    {
        $isA = $tenant === 'A';
        $product = $isA ? $this->productA : $this->productB;
        $price = $isA ? 80.00 : 50.00;
        $qty = $overrides['quantity'] ?? 1;
        unset($overrides['quantity']);

        $total = $price * $qty;

        return array_merge([
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $isA ? $this->customerA->id : $this->customerB->id,
            'warehouse_id' => $isA ? $this->warehouseA->id : $this->warehouseB->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => $total,
            'received_amount' => $total,
            'paid_amount' => $total,
            'payment_status' => Sale::PAID,
            'status' => Sale::COMPLETED,
            'payment_details' => [
                [
                    'amount' => $total,
                    'payment_type' => ['value' => $isA ? $this->pmA->id : $this->pmB->id],
                ],
            ],
            'sale_items' => [
                [
                    'product_id' => $product->id,
                    'product_price' => $price,
                    'net_unit_price' => $price,
                    'tax_type' => Sale::EXCLUSIVE,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => Sale::FIXED,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $this->unitId,
                    'quantity' => $qty,
                    'sub_total' => $total,
                ],
            ],
        ], $overrides);
    }

    public function test_c17_idempotent_retry_returns_original_sale_without_duplicate_sale_payment_or_stock_deduction(): void
    {
        $key = 'pos-retry-' . $this->token;
        $payload = $this->payloadFor('A', ['quantity' => 2]);

        $res1 = $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/sales', $payload);
        $res1->assertStatus(201);
        $saleId = $res1->json('data.id');

        // Exact retry with same Idempotency-Key -> 200 replay, same sale ID, zero duplicates
        $res2 = $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/sales', $payload);
        $res2->assertStatus(200)
            ->assertHeader('X-Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $saleId);

        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenantAId)->count());
        $this->assertSame(1, DB::table('sales_payments')->where('sale_id', $saleId)->count());
        $this->assertEquals(48, (int) ManageStock::withoutGlobalScopes()->where('product_id', $this->productA->id)->value('quantity'));
    }

    public function test_c17_same_idempotency_key_with_different_payload_returns_409_conflict(): void
    {
        $key = 'pos-mismatch-' . $this->token;

        $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/sales', $this->payloadFor('A', ['quantity' => 1]))
            ->assertStatus(201);

        $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/sales', $this->payloadFor('A', ['quantity' => 3]))
            ->assertStatus(409);

        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenantAId)->count());
    }

    public function test_c17_failed_422_checkout_releases_idempotency_key_so_corrected_retry_succeeds(): void
    {
        $key = 'pos-recover-' . $this->token;

        // 1. Failing validation payload (negative discount) -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/sales', $this->payloadFor('A', ['discount' => -15]))
            ->assertStatus(422);

        // 2. Corrected retry with the exact same Idempotency-Key -> 201
        $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/sales', $this->payloadFor('A', ['discount' => 0]))
            ->assertStatus(201);

        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenantAId)->count());
    }

    public function test_c17_idempotency_key_is_isolated_per_tenant_and_verifies_replay_ownership(): void
    {
        $sharedKey = 'pos-shared-key-' . $this->token;

        // Tenant A creates sale with sharedKey
        $resA = $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $sharedKey)
            ->postJson('/api/sales', $this->payloadFor('A'));
        $resA->assertStatus(201);

        // Tenant B using the identical key string creates its OWN sale (never replays Tenant A's sale)
        $resB = $this->actingAs($this->adminB, 'sanctum')
            ->withHeader('Idempotency-Key', $sharedKey)
            ->postJson('/api/sales', $this->payloadFor('B'));
        $resB->assertStatus(201);
        $this->assertNotSame($resA->json('data.id'), $resB->json('data.id'));

        // Verify tampered replay record pointing to foreign tenant's sale is rejected (409)
        $tamperedKey = 'pos-tampered-' . $this->token;
        $payloadA = $this->payloadFor('A');
        $service = app(SaleIdempotencyService::class);
        $recordKey = "pos_sale_idempotency:{$this->tenantAId}:{$this->adminA->id}:" . hash('sha256', $tamperedKey);
        Cache::put($recordKey, [
            'status' => 'completed',
            'sale_id' => $resB->json('data.id'), // Tenant B's sale!
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'payload_hash' => $service->computePayloadHash($payloadA),
        ], 60);

        $this->actingAs($this->adminA, 'sanctum')
            ->withHeader('Idempotency-Key', $tamperedKey)
            ->postJson('/api/sales', $payloadA)
            ->assertStatus(409);

        Cache::forget($recordKey);
    }

    public function test_c17_checkout_without_idempotency_key_preserves_backward_compatibility(): void
    {
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->payloadFor('A'))
            ->assertStatus(201);

        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenantAId)->count());
    }

    public function test_c17_concurrent_in_flight_lock_returns_409_conflict_without_duplicate_sale(): void
    {
        $key = 'pos-concurrent-' . $this->token;
        $lockKey = "pos_sale_idempotency_lock:{$this->tenantAId}:{$this->adminA->id}:" . hash('sha256', $key);
        $heldLock = Cache::lock($lockKey, 60);
        $this->assertTrue($heldLock->get());

        try {
            $this->actingAs($this->adminA, 'sanctum')
                ->withHeader('Idempotency-Key', $key)
                ->postJson('/api/sales', $this->payloadFor('A'))
                ->assertStatus(409);

            $this->assertSame(0, DB::table('sales')->where('tenant_id', $this->tenantAId)->count());
        } finally {
            $heldLock->release();
        }
    }

    public function test_c17_post_commit_failure_before_response_replays_committed_sale_on_retry(): void
    {
        $key = 'pos-post-commit-' . $this->token;
        $payload = $this->payloadFor('A');
        $service = app(SaleIdempotencyService::class);
        $repo = app(\App\Repositories\SaleRepository::class);

        $this->actingAs($this->adminA);
        $createCalls = 0;

        // First attempt: sale commits to DB, then post-commit step throws before HTTP response completes
        $threwPostCommit = false;
        try {
            $service->execute(
                $this->adminA,
                $key,
                $payload,
                function () use ($repo, $payload, &$createCalls) {
                    $createCalls++;
                    return $repo->storeSale($payload);
                },
                function () {
                    throw new \RuntimeException('Simulated post-commit timeout before response');
                }
            );
        } catch (\RuntimeException $e) {
            $threwPostCommit = true;
        }

        $this->assertTrue($threwPostCommit);
        $this->assertSame(1, $createCalls);
        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenantAId)->count());
        $committedSaleId = DB::table('sales')->where('tenant_id', $this->tenantAId)->value('id');

        // Retry with the same key: MUST replay committed sale without calling createSaleCallback again
        $replay = $service->execute(
            $this->adminA,
            $key,
            $payload,
            function () use (&$createCalls) {
                $createCalls++;
                throw new \LogicException('Must not create a duplicate sale on retry!');
            }
        );

        $this->assertTrue($replay['replayed']);
        $this->assertSame((int) $committedSaleId, (int) $replay['sale']->id);
        $this->assertSame(1, $createCalls);
        $this->assertSame(1, DB::table('sales')->where('tenant_id', $this->tenantAId)->count());
        $this->assertEquals(49, (int) ManageStock::withoutGlobalScopes()->where('product_id', $this->productA->id)->value('quantity'));
    }
}
