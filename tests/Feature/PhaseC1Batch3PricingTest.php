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
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhaseC1Batch3PricingTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $cashierA = null;
    private ?User $adminB = null;
    private ?Role $cashierRoleA = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $plan = null;
    private ?Warehouse $warehouseA = null;
    private ?Warehouse $warehouseB = null;
    private ?Customer $customerA = null;
    private ?Brand $brandA = null;
    private ?Brand $brandB = null;
    private ?ProductCategory $categoryA = null;
    private ?ProductCategory $categoryB = null;
    private ?MainProduct $mainProductA = null;
    private ?MainProduct $mainProductB = null;
    private ?Product $productA = null;
    private ?Product $productB = null;
    private ?PaymentMethod $pmA = null;
    private int $unitId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c1b3_tA_' . $this->token;
        $this->tenantBId = 'c1b3_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c1b3_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c1b3_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C1B3AdminA_{$this->token}", "c1b3.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("C1B3AdminB_{$this->token}", "c1b3.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);

        // Cashier role: manage_sale + manage_pos_screen ONLY (NO manage_products)
        $this->cashierRoleA = Role::withoutGlobalScopes()->create([
            'name' => "c1b3_cashier_{$this->token}",
            'display_name' => 'POS Cashier',
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        foreach (['manage_sale', 'manage_pos_screen'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
            $this->cashierRoleA->givePermissionTo($perm);
        }
        $this->cashierA = $this->makeUser("C1B3Cashier_{$this->token}", "c1b3.cash.{$this->token}@stockmate.test", $this->tenantAId, $this->cashierRoleA->name);

        $this->storeA = Store::create(['name' => "C1B3 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "C1B3 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->cashierA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "C1B3 Plan {$this->token}",
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
            'name' => "C1B3 Wh A {$this->token}",
            'phone' => '1111111111',
            'country' => 'US',
            'city' => 'NYC',
            'email' => "c1b3.wha.{$this->token}@stockmate.test",
            'zip_code' => '10001',
        ]);
        $this->warehouseB = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C1B3 Wh B {$this->token}",
            'phone' => '2222222222',
            'country' => 'US',
            'city' => 'LA',
            'email' => "c1b3.whb.{$this->token}@stockmate.test",
            'zip_code' => '90001',
        ]);

        $this->customerA = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C1B3 Cust A {$this->token}",
            'email' => "c1b3.ca.{$this->token}@stockmate.test",
            'phone' => '1112223333',
            'country' => 'US',
            'city' => 'NYC',
            'address' => '123 Main St',
        ]);

        $this->brandA = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantAId, 'name' => "C1B3 Brand A {$this->token}"]);
        $this->brandB = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantBId, 'name' => "C1B3 Brand B {$this->token}"]);
        $this->categoryA = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantAId, 'name' => "C1B3 Cat A {$this->token}"]);
        $this->categoryB = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantBId, 'name' => "C1B3 Cat B {$this->token}"]);

        $unit = Unit::first();
        $this->unitId = $unit ? (int) $unit->id : 1;

        $this->mainProductA = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C1B3 Main A {$this->token}",
            'code' => "C1B3-MA-{$this->token}",
            'product_unit' => (string) $this->unitId,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);
        $this->mainProductB = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C1B3 Main B {$this->token}",
            'code' => "C1B3-MB-{$this->token}",
            'product_unit' => (string) $this->unitId,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);

        $this->productA = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $this->mainProductA->id,
            'name' => "C1B3 Product A {$this->token}",
            'code' => "C1B3-PA-{$this->token}",
            'product_code' => "C1B3-SKA-{$this->token}",
            'product_category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 60.00,
            'product_price' => 100.00,
            'product_unit' => (string) $this->unitId,
            'sale_unit' => (string) $this->unitId,
            'purchase_unit' => (string) $this->unitId,
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);
        $this->productB = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantBId,
            'main_product_id' => $this->mainProductB->id,
            'name' => "C1B3 Product B {$this->token}",
            'code' => "C1B3-PB-{$this->token}",
            'product_code' => "C1B3-SKB-{$this->token}",
            'product_category_id' => $this->categoryB->id,
            'brand_id' => $this->brandB->id,
            'product_cost' => 30.00,
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

        $this->pmA = $this->createWithoutTenantScope(PaymentMethod::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "Cash A {$this->token}",
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

        $userIds = array_filter([$this->adminA?->id, $this->cashierA?->id, $this->adminB?->id]);
        if (! empty($userIds)) {
            Subscription::whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            Store::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->delete();
            User::whereIn('id', $userIds)->forceDelete();
        }
        if ($this->cashierRoleA) {
            DB::table('role_has_permissions')->where('role_id', $this->cashierRoleA->id)->delete();
            DB::table('roles')->where('id', $this->cashierRoleA->id)->delete();
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
            'phone' => '92316' . random_int(1000000, 9999999),
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

    private function buildSalePayload(array $overrides = []): array
    {
        $itemOverrides = $overrides['item'] ?? [];
        unset($overrides['item']);

        $item = array_merge([
            'product_id' => $this->productA->id,
            'product_price' => 100.00,
            'net_unit_price' => 100.00,
            'tax_type' => Sale::EXCLUSIVE,
            'tax_value' => 0,
            'tax_amount' => 0,
            'discount_type' => Sale::FIXED,
            'discount_value' => 0,
            'discount_amount' => 0,
            'sale_unit' => $this->unitId,
            'quantity' => 1,
            'sub_total' => 100.00,
        ], $itemOverrides);

        return array_merge([
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 100.00,
            'received_amount' => 100.00,
            'paid_amount' => 100.00,
            'payment_status' => Sale::PAID,
            'status' => Sale::COMPLETED,
            'payment_details' => [
                [
                    'amount' => 100.00,
                    'payment_type' => ['value' => $this->pmA->id],
                ],
            ],
            'sale_items' => [$item],
        ], $overrides);
    }

    public function test_c15_unauthorized_cashier_cannot_tamper_with_product_price_or_use_foreign_product(): void
    {
        // 1. Tampered product_price (0.01 instead of catalog 100.00) by POS cashier -> 422
        $tamperedRes = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload([
                'item' => ['product_price' => 0.01, 'net_unit_price' => 0.01, 'sub_total' => 0.01],
                'grand_total' => 0.01,
            ]));
        $tamperedRes->assertStatus(422);
        $this->assertEquals(50, (int) ManageStock::withoutGlobalScopes()->where('product_id', $this->productA->id)->value('quantity'));

        // 2. Cross-tenant product_id -> 422
        $crossTenantRes = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload([
                'item' => ['product_id' => $this->productB->id, 'product_price' => 50.00],
            ]));
        $crossTenantRes->assertStatus(422);

        // 3. Valid catalog price (100.00) with legitimate 10% item discount & 5% order tax -> 201 (90 * 1.05 = 94.50)
        $validRes = $this->actingAs($this->cashierA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload([
                'item' => [
                    'product_price' => 100.00,
                    'discount_type' => Sale::PERCENTAGE,
                    'discount_value' => 10,
                ],
                'tax_rate' => 5,
                'grand_total' => 94.50,
                'payment_details' => [['amount' => 94.50, 'payment_type' => ['value' => $this->pmA->id]]],
            ]));
        $validRes->assertStatus(201);
        $saleId = $validRes->json('data.id');
        $this->assertEquals(94.50, (float) $validRes->json('data.attributes.grand_total'));

        // 4. Cashier attempting to tamper product_price on PUT /api/sales/{id} -> 422
        $saleItemId = DB::table('sale_items')->where('sale_id', $saleId)->value('id');
        $tamperedUpdate = $this->actingAs($this->cashierA, 'sanctum')
            ->putJson("/api/sales/{$saleId}", $this->buildSalePayload([
                'item' => [
                    'sale_item_id' => $saleItemId,
                    'product_price' => 1.00,
                ],
                'grand_total' => 1.00,
            ]));
        $tamperedUpdate->assertStatus(422);
        $this->assertEquals(100.00, (float) DB::table('sale_items')->where('id', $saleItemId)->value('product_price'));
    }

    public function test_c15_authorized_admin_can_override_product_price_but_negative_price_is_rejected(): void
    {
        // Store Admin legitimately overrides catalog price 100.00 -> 85.00 -> 201
        $overrideRes = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload([
                'item' => ['product_price' => 85.00, 'net_unit_price' => 85.00, 'sub_total' => 85.00],
                'grand_total' => 85.00,
                'payment_details' => [['amount' => 85.00, 'payment_type' => ['value' => $this->pmA->id]]],
            ]));
        $overrideRes->assertStatus(201);
        $this->assertEquals(85.00, (float) $overrideRes->json('data.attributes.grand_total'));

        // Even Store Admin cannot submit negative product_price -> 422
        $negativePriceRes = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload([
                'item' => ['product_price' => -10.00],
            ]));
        $negativePriceRes->assertStatus(422);
    }

    public function test_c16_negative_order_discount_is_rejected_and_valid_boundary_discounts_succeed(): void
    {
        // 1. Negative order discounts (-0.01 and -50.00) -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload(['discount' => -0.01]))
            ->assertStatus(422);

        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload(['discount' => -50.00]))
            ->assertStatus(422);

        // 2. Zero discount boundary (0) -> 201 (grand_total = 100.00)
        $zeroRes = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload(['discount' => 0]));
        $zeroRes->assertStatus(201);
        $this->assertEquals(100.00, (float) $zeroRes->json('data.attributes.grand_total'));

        // 3. Valid positive discount (20.00) + 10% tax -> 201 ((100 - 20) * 1.10 = 88.00)
        $posRes = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload([
                'discount' => 20.00,
                'tax_rate' => 10,
                'grand_total' => 88.00,
                'payment_details' => [['amount' => 88.00, 'payment_type' => ['value' => $this->pmA->id]]],
            ]));
        $posRes->assertStatus(201);
        $saleId = $posRes->json('data.id');
        $this->assertEquals(88.00, (float) $posRes->json('data.attributes.grand_total'));

        // 4. Discount equal to subtotal (100.00) -> 201 (grand_total = 0.00)
        $fullDiscRes = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload([
                'discount' => 100.00,
                'grand_total' => 0,
                'payment_status' => Sale::UNPAID,
                'payment_details' => [],
            ]));
        $fullDiscRes->assertStatus(201);
        $this->assertEquals(0.00, (float) $fullDiscRes->json('data.attributes.grand_total'));

        // 5. Discount exceeding subtotal (100.01) -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', $this->buildSalePayload(['discount' => 100.01]))
            ->assertStatus(422);

        // 6. Negative discount on update (PUT /api/sales/{id}) -> 422
        $saleItemId = DB::table('sale_items')->where('sale_id', $saleId)->value('id');
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/sales/{$saleId}", $this->buildSalePayload([
                'discount' => -5.00,
                'item' => ['sale_item_id' => $saleItemId],
            ]))
            ->assertStatus(422);
    }

    public function test_c15_permission_based_override_allows_manager_sub_user_with_manage_products_and_denies_without(): void
    {
        $managerRole = Role::withoutGlobalScopes()->create([
            'name' => "c1b3_mgr_{$this->token}",
            'display_name' => 'Pricing Manager',
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        foreach (['manage_sale', 'manage_pos_screen', 'manage_products'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
            $managerRole->givePermissionTo($perm);
        }
        $managerSubUser = $this->makeUser("C1B3Mgr_{$this->token}", "c1b3.mgr.{$this->token}@stockmate.test", $this->tenantAId, $managerRole->name);
        UserStore::create(['user_id' => $managerSubUser->id, 'store_id' => $this->storeA->id]);

        try {
            // Sub-user WITH manage_products -> authorized price override succeeds (201)
            $allowedRes = $this->actingAs($managerSubUser, 'sanctum')
                ->postJson('/api/sales', $this->buildSalePayload([
                    'item' => ['product_price' => 92.00, 'net_unit_price' => 92.00, 'sub_total' => 92.00],
                    'grand_total' => 92.00,
                    'payment_details' => [['amount' => 92.00, 'payment_type' => ['value' => $this->pmA->id]]],
                ]));
            $allowedRes->assertStatus(201);
            $this->assertEquals(92.00, (float) $allowedRes->json('data.attributes.grand_total'));

            // Sub-user WITHOUT manage_products (cashierA) -> price override denied (422)
            $this->actingAs($this->cashierA, 'sanctum')
                ->postJson('/api/sales', $this->buildSalePayload([
                    'item' => ['product_price' => 92.00, 'net_unit_price' => 92.00, 'sub_total' => 92.00],
                    'grand_total' => 92.00,
                ]))
                ->assertStatus(422);
        } finally {
            UserStore::where('user_id', $managerSubUser->id)->delete();
            DB::table('model_has_roles')->where('model_id', $managerSubUser->id)->delete();
            User::where('id', $managerSubUser->id)->forceDelete();
            DB::table('role_has_permissions')->where('role_id', $managerRole->id)->delete();
            DB::table('roles')->where('id', $managerRole->id)->delete();
        }
    }

    public function test_c15_direct_repository_invocation_rejects_foreign_tenant_product_and_unauthenticated_context(): void
    {
        $repo = app(\App\Repositories\SaleRepository::class);
        $foreignItem = [
            'product_id' => $this->productB->id,
            'product_price' => 50.00,
            'tax_type' => Sale::EXCLUSIVE,
            'tax_value' => 0,
            'discount_type' => Sale::FIXED,
            'discount_value' => 0,
            'sale_unit' => $this->unitId,
            'quantity' => 1,
        ];

        // 1. Authenticated as Tenant A, direct repository call with Tenant B product_id -> rejected
        $this->actingAs($this->adminA);
        $threwById = false;
        try {
            $repo->calculationSaleItems($foreignItem);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
            $threwById = true;
        }
        $this->assertTrue($threwById, 'Direct calculationSaleItems must reject foreign-tenant product_id.');

        // 2. Authenticated as Tenant A, direct repository call passing pre-fetched Tenant B Product model -> rejected
        $threwWithModel = false;
        try {
            $repo->calculationSaleItems($foreignItem, $this->productB);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
            $threwWithModel = true;
        }
        $this->assertTrue($threwWithModel, 'Direct calculationSaleItems must reject pre-fetched foreign-tenant Product model.');

        // 3. Unauthenticated direct repository call -> fail-closed (rejected, never unscoped)
        \Illuminate\Support\Facades\Auth::logout();
        $threwUnauth = false;
        try {
            $repo->calculationSaleItems([
                'product_id' => $this->productA->id,
                'product_price' => 100.00,
                'tax_type' => Sale::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Sale::FIXED,
                'discount_value' => 0,
                'sale_unit' => $this->unitId,
                'quantity' => 1,
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException $e) {
            $threwUnauth = true;
        }
        $this->assertTrue($threwUnauth, 'Direct calculationSaleItems without active tenant context must fail closed.');
    }
}
