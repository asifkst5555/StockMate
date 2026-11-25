<?php

namespace Tests\Feature;

use App\Exports\StockReportExport;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\ManageStock;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use App\Repositories\UnitRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhaseC2Batch5ABackendTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?User $subUserA = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $planA = null;
    private ?Warehouse $warehouseA = null;
    private ?Supplier $supplierA = null;
    private ?BaseUnit $baseUnitA = null;
    private ?BaseUnit $baseUnitB = null;
    private ?Unit $unitA1 = null;
    private ?Unit $unitA2 = null;
    private ?Unit $unitB1 = null;
    private ?Brand $brandA = null;
    private ?ProductCategory $catA = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c2b5_tA_' . $this->token;
        $this->tenantBId = 'c2b5_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c2b5_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c2b5_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C2B5AdminA_{$this->token}", "c2b5.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("C2B5AdminB_{$this->token}", "c2b5.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);
        $this->subUserA = $this->makeUser("C2B5SubA_{$this->token}", "c2b5.suba.{$this->token}@stockmate.test", $this->tenantAId, null);

        $this->storeA = Store::create(['name' => "C2B5 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "C2B5 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);
        UserStore::create(['user_id' => $this->subUserA->id, 'store_id' => $this->storeA->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->planA = Plan::create([
            'name' => "C2B5 Plan {$this->token}",
            'price' => 0,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $currency->id,
            'no_of_stores' => 5,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        PlanFeature::create([
            'plan_id' => $this->planA->id,
            'inventory_management' => true,
            'pos_management' => true,
            'reports' => true,
            'adjustments' => true,
            'roles_permission' => true,
            'sms_support' => true,
            'emails_support' => true,
        ]);

        foreach ([$this->adminA, $this->adminB] as $owner) {
            Subscription::create([
                'user_id' => $owner->id,
                'plan_id' => $this->planA->id,
                'payment_type' => Subscription::TYPE_FREE,
                'plan_amount' => 0,
                'plan_frequency' => Plan::MONTHLY,
                'start_date' => Carbon::now()->subDay(),
                'end_date' => Carbon::now()->addYear(),
                'status' => Subscription::ACTIVE,
                'no_of_stores' => 5,
            ]);
        }

        $this->warehouseA = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B5WH_A_{$this->token}", 'phone' => '1111111111', 'country' => 'US', 'city' => 'NY', 'email' => "wha.{$this->token}@test.local",
        ]);

        $this->supplierA = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B5Sup_A_{$this->token}", 'email' => "supa.{$this->token}@test.local", 'phone' => '1111111111', 'country' => 'US', 'city' => 'NY', 'address' => 'Addr A',
        ]);

        $this->baseUnitA = $this->createWithoutTenantScope(BaseUnit::class, [
            'tenant_id' => $this->tenantAId, 'name' => "Kg_{$this->token}", 'is_default' => 0,
        ]);
        $this->baseUnitB = $this->createWithoutTenantScope(BaseUnit::class, [
            'tenant_id' => $this->tenantBId, 'name' => "KgB_{$this->token}", 'is_default' => 0,
        ]);

        $this->unitA1 = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId, 'name' => "Kilogram_{$this->token}", 'short_name' => 'kg', 'base_unit' => $this->baseUnitA->id,
        ]);
        $this->unitA2 = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId, 'name' => "Gram_{$this->token}", 'short_name' => 'g', 'base_unit' => $this->baseUnitA->id,
        ]);
        $this->unitB1 = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantBId, 'name' => "KilogramB_{$this->token}", 'short_name' => 'kgB', 'base_unit' => $this->baseUnitB->id,
        ]);

        $this->brandA = $this->createWithoutTenantScope(Brand::class, [
            'tenant_id' => $this->tenantAId, 'name' => "BrandA_{$this->token}",
        ]);
        $this->catA = $this->createWithoutTenantScope(ProductCategory::class, [
            'tenant_id' => $this->tenantAId, 'name' => "CatA_{$this->token}",
        ]);

        $this->productA = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "SeedProd_{$this->token}",
            'code' => "SEED-{$this->token}",
            'product_code' => "SKU-SEED-{$this->token}",
            'product_category_id' => $this->catA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 10,
            'product_price' => 20,
            'product_unit' => $this->unitA1->id,
            'sale_unit' => $this->unitA1->id,
            'purchase_unit' => $this->unitA1->id,
            'barcode_symbol' => 1,
        ]);
    }

    private function makeUser(string $name, string $email, ?string $tenantId, ?string $roleName): User
    {
        $user = User::create([
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => $email,
            'password' => bcrypt('Secret123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        if ($roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user->assignRole($role);
        }

        return $user;
    }

    private function createWithoutTenantScope(string $modelClass, array $attributes)
    {
        return $modelClass::withoutGlobalScopes()->create($attributes);
    }

    /**
     * B5-01: Same SKU in Tenant A and Tenant B is allowed; duplicate within same tenant is rejected.
     */
    public function test_tenant_scoped_product_sku_uniqueness(): void
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $sku = "SKU-B5-{$this->token}";
        $payloadA = [
            'name' => 'Product A',
            'code' => "BC-A-{$this->token}",
            'product_code' => $sku,
            'product_category_id' => $this->catA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 10,
            'product_price' => 15,
            'product_unit' => $this->unitA1->id,
            'sale_unit' => $this->unitA1->id,
            'purchase_unit' => $this->unitA1->id,
            'barcode_symbol' => 1,
            'product_type' => 1,
        ];

        // 1. Tenant A creates SKU-B5 -> Success
        $respA = $this->postJson('/api/main-products', $payloadA);
        $this->assertTrue(in_array($respA->status(), [200, 201]), "Unexpected status: {$respA->status()}");

        // 2. Tenant A tries to create duplicate SKU-B5 -> Rejected (422)
        $payloadADup = $payloadA;
        $payloadADup['code'] = "BC-A2-{$this->token}";
        $respADup = $this->postJson('/api/main-products', $payloadADup);
        $respADup->assertStatus(422);
        $this->assertFalse($respADup->json('success'));

        // 3. Tenant B creates SAME SKU-B5 in Tenant B -> Success (Allowed across tenants!)
        Sanctum::actingAs($this->adminB, ['*']);
        $catB = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantBId, 'name' => "CatB_{$this->token}"]);
        $brandB = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantBId, 'name' => "BrandB_{$this->token}"]);

        $payloadB = [
            'name' => 'Product B',
            'code' => "BC-B-{$this->token}",
            'product_code' => $sku,
            'product_category_id' => $catB->id,
            'brand_id' => $brandB->id,
            'product_cost' => 20,
            'product_price' => 25,
            'product_unit' => $this->unitB1->id,
            'sale_unit' => $this->unitB1->id,
            'purchase_unit' => $this->unitB1->id,
            'barcode_symbol' => 1,
            'product_type' => 1,
        ];
        $respB = $this->postJson('/api/main-products', $payloadB);
        $this->assertTrue(in_array($respB->status(), [200, 201]), "Unexpected status: {$respB->status()}");
    }

    /**
     * B5-01: Updating product without changing SKU is allowed; updating to another product's SKU in same tenant is rejected.
     */
    public function test_updating_product_sku_uniqueness_bounds(): void
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $sku1 = "SKU1-{$this->token}";
        $sku2 = "SKU2-{$this->token}";

        $prod1Resp = $this->postJson('/api/main-products', [
            'name' => 'P1', 'code' => "BC1-{$this->token}", 'product_code' => $sku1,
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 10, 'product_price' => 15, 'product_unit' => $this->unitA1->id,
            'sale_unit' => $this->unitA1->id, 'purchase_unit' => $this->unitA1->id,
            'barcode_symbol' => 1, 'product_type' => 1,
        ]);
        $this->assertTrue(in_array($prod1Resp->status(), [200, 201]));
        $prod1 = $prod1Resp->json('data.attributes.main_product_id');

        $prod2Resp = $this->postJson('/api/main-products', [
            'name' => 'P2', 'code' => "BC2-{$this->token}", 'product_code' => $sku2,
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 10, 'product_price' => 15, 'product_unit' => $this->unitA1->id,
            'sale_unit' => $this->unitA1->id, 'purchase_unit' => $this->unitA1->id,
            'barcode_symbol' => 1, 'product_type' => 1,
        ]);
        $this->assertTrue(in_array($prod2Resp->status(), [200, 201]));
        $prod2 = $prod2Resp->json('data.attributes.main_product_id');

        // Update P1 with SAME sku1 -> Success
        $respUpdateSame = $this->postJson("/api/main-products/{$prod1}", [
            'name' => 'P1 Updated', 'product_code' => $sku1,
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_unit' => $this->unitA1->id, 'barcode_symbol' => 1,
        ]);
        $this->assertTrue(in_array($respUpdateSame->status(), [200, 201]), "Unexpected update status: {$respUpdateSame->status()}");

        // Update P1 with P2's sku2 -> Rejected (422)
        $this->postJson("/api/main-products/{$prod1}", [
            'name' => 'P1 Bad', 'product_code' => $sku2,
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_unit' => $this->unitA1->id, 'barcode_symbol' => 1,
        ])->assertStatus(422);
    }

    /**
     * B5-02: Product Unit Validation & 1:1 Unit Parity.
     */
    public function test_product_unit_tenant_and_parity_validation(): void
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // 1. Cross-tenant unit ID -> Rejected (422)
        $respCrossTenant = $this->postJson('/api/main-products', [
            'name' => 'P Cross Unit', 'code' => "BC-CU-{$this->token}", 'product_code' => "SKU-CU-{$this->token}",
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 10, 'product_price' => 15,
            'product_unit' => $this->unitB1->id, // Tenant B unit!
            'barcode_symbol' => 1, 'product_type' => 1,
        ]);
        $respCrossTenant->assertStatus(422);
        $this->assertFalse($respCrossTenant->json('success'));

        // 2. Mismatched purchase_unit != sale_unit without conversion factor -> Rejected (422)
        $respMismatch = $this->postJson('/api/main-products', [
            'name' => 'P Mismatch Units', 'code' => "BC-MU-{$this->token}", 'product_code' => "SKU-MU-{$this->token}",
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 10, 'product_price' => 15,
            'product_unit' => $this->unitA1->id,
            'purchase_unit' => $this->unitA1->id,
            'sale_unit' => $this->unitA2->id, // Mismatched unit!
            'barcode_symbol' => 1, 'product_type' => 1,
        ]);
        $respMismatch->assertStatus(422);
        $this->assertFalse($respMismatch->json('success'));
        $this->assertStringContainsString('unit', strtolower($respMismatch->json('message')));

        // 3. Matching units -> Success
        $respMatch = $this->postJson('/api/main-products', [
            'name' => 'P Valid Units', 'code' => "BC-VU-{$this->token}", 'product_code' => "SKU-VU-{$this->token}",
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 10, 'product_price' => 15,
            'product_unit' => $this->unitA1->id,
            'purchase_unit' => $this->unitA1->id,
            'sale_unit' => $this->unitA1->id,
            'barcode_symbol' => 1, 'product_type' => 1,
        ]);
        $this->assertTrue(in_array($respMatch->status(), [200, 201]));
    }

    /**
     * B5-04: Fix Product Unit Accessors in Product and ManageStock models.
     */
    public function test_product_and_manage_stock_unit_accessors(): void
    {
        $product = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "AccessorProd_{$this->token}",
            'code' => "ACC-{$this->token}",
            'product_code' => "SKU-ACC-{$this->token}",
            'product_category_id' => $this->catA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 10,
            'product_price' => 20,
            'product_unit' => $this->unitA1->id, // Points to Unit model id!
            'sale_unit' => $this->unitA1->id,
            'purchase_unit' => $this->unitA1->id,
            'barcode_symbol' => 1,
        ]);

        $stock = $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantAId,
            'warehouse_id' => $this->warehouseA->id,
            'product_id' => $product->id,
            'quantity' => 100,
        ]);

        // Product accessor returns unit array with matching name
        $productUnitData = $product->getProductUnitName();
        $this->assertIsArray($productUnitData);
        $this->assertEquals("Kilogram_{$this->token}", $productUnitData['name']);

        // ManageStock accessor returns unit string name
        $manageStockUnitName = $stock->getProductUnitName();
        $this->assertEquals("Kilogram_{$this->token}", $manageStockUnitName);

        // Missing unit returns empty string fallback
        $product->product_unit = 99999999;
        $this->assertEquals('', $product->getProductUnitName());
    }

    /**
     * B5-05: Unit Deletion Reference Protection.
     */
    public function test_unit_deletion_reference_protection(): void
    {
        /** @var UnitRepository $unitRepo */
        $unitRepo = app(UnitRepository::class);

        // 1. Unused unit -> Can be deleted (unitCantDelete = false)
        $unusedUnit = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId, 'name' => "Unused_{$this->token}", 'short_name' => 'un', 'base_unit' => $this->baseUnitA->id,
        ]);
        $this->assertFalse($unitRepo->unitCantDelete($unusedUnit->id));

        // 2. Unit referenced as product_unit -> Blocked (unitCantDelete = true)
        $prodUnit = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId, 'name' => "ProdU_{$this->token}", 'short_name' => 'pu', 'base_unit' => $this->baseUnitA->id,
        ]);
        $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId, 'name' => "P_{$this->token}", 'code' => "PPU-{$this->token}", 'product_code' => "SKU-PPU-{$this->token}",
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 5, 'product_price' => 10,
            'product_unit' => $prodUnit->id, // Referenced!
            'barcode_symbol' => 1,
        ]);
        $this->assertTrue($unitRepo->unitCantDelete($prodUnit->id));

        // 3. Unit referenced in purchase_items -> Blocked
        $purchaseUnit = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId, 'name' => "PurU_{$this->token}", 'short_name' => 'puru', 'base_unit' => $this->baseUnitA->id,
        ]);
        $purchase = $this->createWithoutTenantScope(Purchase::class, [
            'tenant_id' => $this->tenantAId, 'supplier_id' => $this->supplierA->id, 'warehouse_id' => $this->warehouseA->id,
            'date' => now(), 'status' => Purchase::RECEIVED, 'grand_total' => 100,
        ]);
        $this->createWithoutTenantScope(PurchaseItem::class, [
            'purchase_id' => $purchase->id, 'product_id' => $product = Product::first()->id, 'quantity' => 10,
            'product_cost' => 10, 'net_unit_cost' => 10, 'sub_total' => 100, 'purchase_unit' => $purchaseUnit->id,
            'tax_type' => 1, 'discount_type' => 1,
        ]);
        $this->assertTrue($unitRepo->unitCantDelete($purchaseUnit->id));
    }

    /**
     * B5-06 & B5-07: Granular RBAC Route Alignment & Base Units Protection.
     */
    public function test_granular_rbac_and_base_units_protection(): void
    {
        // 1. Base units API rejects unauthorized sub-user (403)
        Sanctum::actingAs($this->subUserA, ['*']);
        $this->postJson('/api/base-units', ['name' => 'HackerUnit'])->assertStatus(403);

        // 2. User with only view_purchase can list purchases, but cannot create or delete
        $roleViewPurchase = Role::firstOrCreate(['name' => "RoleViewPurchase_{$this->token}", 'guard_name' => 'web']);
        $viewPerm = Permission::firstOrCreate(['name' => 'view_purchase', 'guard_name' => 'web']);
        $roleViewPurchase->syncPermissions([$viewPerm]);
        $this->subUserA->syncRoles([$roleViewPurchase]);

        // Can view purchases
        $this->getJson('/api/purchases')->assertStatus(200);

        $purchasePayload = [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'date' => now()->format('Y-m-d'),
            'status' => Purchase::PENDING,
            'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'purchase_items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 5,
                    'product_cost' => 10,
                    'net_unit_cost' => 10,
                    'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'purchase_unit' => $this->unitA1->id,
                    'sub_total' => 50,
                ],
            ],
        ];

        // Cannot create purchase (403)
        $this->postJson('/api/purchases', $purchasePayload)->assertStatus(403);

        // 3. User with create_purchase can create
        $roleCreatePurchase = Role::firstOrCreate(['name' => "RoleCreatePurchase_{$this->token}", 'guard_name' => 'web']);
        $createPerm = Permission::firstOrCreate(['name' => 'create_purchase', 'guard_name' => 'web']);
        $roleCreatePurchase->syncPermissions([$createPerm, $viewPerm]);
        $this->subUserA->syncRoles([$roleCreatePurchase]);

        $createdPurchaseResp = $this->postJson('/api/purchases', $purchasePayload);
        $this->assertTrue(in_array($createdPurchaseResp->status(), [200, 201]));
        $createdPurchase = $createdPurchaseResp->json('data.id');

        // But still CANNOT delete purchase (403)
        $this->deleteJson("/api/purchases/{$createdPurchase}")->assertStatus(403);

        // 4. User with manage_purchase can delete
        $roleManagePurchase = Role::firstOrCreate(['name' => "RoleManagePurchase_{$this->token}", 'guard_name' => 'web']);
        $managePerm = Permission::firstOrCreate(['name' => 'manage_purchase', 'guard_name' => 'web']);
        $roleManagePurchase->syncPermissions([$managePerm]);
        $this->subUserA->syncRoles([$roleManagePurchase]);

        $this->deleteJson("/api/purchases/{$createdPurchase}")->assertStatus(200);
    }

    /**
     * B5-08: Supplier Report SQL Aggregation Optimization.
     */
    public function test_supplier_report_aggregation_optimization(): void
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // Create purchases for supplierA: 100 + 250 = 350
        $this->createWithoutTenantScope(Purchase::class, [
            'tenant_id' => $this->tenantAId, 'supplier_id' => $this->supplierA->id, 'warehouse_id' => $this->warehouseA->id,
            'date' => now(), 'status' => Purchase::RECEIVED, 'grand_total' => 100.50,
        ]);
        $this->createWithoutTenantScope(Purchase::class, [
            'tenant_id' => $this->tenantAId, 'supplier_id' => $this->supplierA->id, 'warehouse_id' => $this->warehouseA->id,
            'date' => now(), 'status' => Purchase::RECEIVED, 'grand_total' => 250.25,
        ]);

        $resp = $this->getJson('/api/supplier-report')->assertStatus(200);
        $suppliers = $resp->json('data.data');

        $found = collect($suppliers)->firstWhere('id', $this->supplierA->id);
        $this->assertNotNull($found);
        $this->assertEquals(350.75, $found['total_grand_amount']);
    }

    /**
     * B5-09: Stock Report Export Warehouse Filtering.
     */
    public function test_stock_report_export_warehouse_filtering(): void
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $product = $this->productA;

        $warehouseA2 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId, 'name' => "WH2_{$this->token}", 'phone' => '123', 'country' => 'US', 'city' => 'NY', 'email' => "wh2.{$this->token}@test.local",
        ]);

        $stock1 = $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantAId, 'warehouse_id' => $this->warehouseA->id, 'product_id' => $product->id, 'quantity' => 50,
        ]);
        $stock2 = $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantAId, 'warehouse_id' => $warehouseA2->id, 'product_id' => $product->id, 'quantity' => 75,
        ]);

        // 1. All warehouses export (omitted warehouse_id) -> contains both stocks
        request()->query->replace([]);
        $export = new StockReportExport();
        $view = $export->view();
        $stocks = $view->getData()['stocks'];
        $this->assertGreaterThanOrEqual(2, $stocks->count());

        // 2. Specific warehouse export -> filtered strictly
        request()->query->replace(['warehouse_id' => $warehouseA2->id]);
        $exportSpecific = new StockReportExport();
        $viewSpecific = $exportSpecific->view();
        $stocksSpecific = $viewSpecific->getData()['stocks'];
        $this->assertEquals(1, $stocksSpecific->where('warehouse_id', $warehouseA2->id)->count());
        $this->assertEquals(0, $stocksSpecific->where('warehouse_id', $this->warehouseA->id)->count());
    }

    /**
     * C2-33: Purchase Duplicate Item Validation.
     */
    public function test_purchase_duplicate_item_validation(): void
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $prodId = $this->productA->id;

        // Duplicate product_id in purchase_items -> Rejected with 422
        $resp = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'date' => now()->format('Y-m-d'),
            'status' => Purchase::PENDING,
            'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'purchase_items' => [
                [
                    'product_id' => $prodId, 'quantity' => 5, 'product_cost' => 10,
                    'net_unit_cost' => 10, 'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'purchase_unit' => $this->unitA1->id, 'sub_total' => 50,
                ],
                [
                    'product_id' => $prodId, 'quantity' => 10, 'product_cost' => 10, // Duplicate!
                    'net_unit_cost' => 10, 'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'purchase_unit' => $this->unitA1->id, 'sub_total' => 100,
                ],
            ],
        ]);

        $resp->assertStatus(422);
        $this->assertFalse($resp->json('success'));
        $this->assertStringContainsString('Duplicate', $resp->json('message'));

        // Unique product_ids -> Success
        $prod2 = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId, 'name' => "P2_{$this->token}", 'code' => "BC-P2-{$this->token}", 'product_code' => "SKU-P2-{$this->token}",
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 10, 'product_price' => 15, 'product_unit' => $this->unitA1->id,
            'sale_unit' => $this->unitA1->id, 'purchase_unit' => $this->unitA1->id, 'barcode_symbol' => 1,
        ]);

        $respValid = $this->postJson('/api/purchases', [
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA->id,
            'date' => now()->format('Y-m-d'),
            'status' => Purchase::PENDING,
            'tax_rate' => 0, 'discount' => 0, 'shipping' => 0,
            'purchase_items' => [
                [
                    'product_id' => $prodId, 'quantity' => 5, 'product_cost' => 10,
                    'net_unit_cost' => 10, 'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'purchase_unit' => $this->unitA1->id, 'sub_total' => 50,
                ],
                [
                    'product_id' => $prod2->id, 'quantity' => 10, 'product_cost' => 10,
                    'net_unit_cost' => 10, 'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'purchase_unit' => $this->unitA1->id, 'sub_total' => 100,
                ],
            ],
        ]);

        $this->assertTrue(in_array($respValid->status(), [200, 201]), "Expected 200 or 201, got: {$respValid->status()}");
    }
}
