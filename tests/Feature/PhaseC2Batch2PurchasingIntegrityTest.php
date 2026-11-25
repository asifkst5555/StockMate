<?php

namespace Tests\Feature;

use App\Imports\ProductImport;
use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;
use Tests\TestCase;

class PhaseC2Batch2PurchasingIntegrityTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $limitedUserA = null;
    private ?User $superAdmin = null;
    private ?Store $storeA = null;
    private ?Plan $plan = null;
    private ?SpatieRole $noSupplierRole = null;
    private ?Warehouse $warehouseA1 = null;
    private ?Warehouse $warehouseA2 = null;
    private ?Supplier $supplierA = null;
    private ?BaseUnit $baseUnitA = null;
    private ?Unit $unitA = null;
    private ?Brand $brandA = null;
    private ?ProductCategory $categoryA = null;
    private ?PaymentMethod $pmA = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c2b2_tA_' . $this->token;
        $this->tenantBId = 'c2b2_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c2b2_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c2b2_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C2B2AdminA_{$this->token}", "c2b2.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);

        $this->noSupplierRole = SpatieRole::create([
            'name' => 'c2b2_nosup_' . $this->token,
            'display_name' => 'No Supplier Import',
            'guard_name' => 'web',
        ]);
        $this->noSupplierRole->syncPermissions(['manage_dashboard']);
        $this->limitedUserA = $this->makeUser("C2B2LimA_{$this->token}", "c2b2.lim.{$this->token}@stockmate.test", $this->tenantAId, $this->noSupplierRole->name);

        $this->superAdmin = User::withoutGlobalScope('tenant')->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))->first();

        $this->storeA = Store::create(['name' => "C2B2 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->limitedUserA->id, 'store_id' => $this->storeA->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "C2B2 Plan {$this->token}",
            'price' => 0,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $currency->id,
            'no_of_stores' => 5,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->plan->id,
            'payment_type' => Subscription::TYPE_FREE,
            'plan_amount' => 0,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => Carbon::now()->subDay(),
            'end_date' => Carbon::now()->addYear(),
            'status' => Subscription::ACTIVE,
            'no_of_stores' => 5,
        ]);

        $this->warehouseA1 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2B2WH1_{$this->token}",
            'phone' => '1111111111',
            'country' => 'US',
            'city' => 'NY',
            'email' => "wh1.{$this->token}@test.local",
        ]);
        $this->warehouseA2 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2B2WH2_{$this->token}",
            'phone' => '2222222222',
            'country' => 'US',
            'city' => 'LA',
            'email' => "wh2.{$this->token}@test.local",
        ]);

        $this->supplierA = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2B2SupA_{$this->token}",
            'email' => "supa.{$this->token}@test.local",
            'phone' => '5555555555',
            'country' => 'US',
            'city' => 'NY',
            'address' => '10 Supply Ave',
        ]);

        $this->pmA = $this->createWithoutTenantScope(PaymentMethod::class, [
            'tenant_id' => $this->tenantAId,
            'name' => 'Cash',
            'status' => 1,
        ]);

        $this->baseUnitA = $this->createWithoutTenantScope(BaseUnit::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "PieceA_{$this->token}",
        ]);
        $this->unitA = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "UnitA_{$this->token}",
            'short_name' => 'pc',
            'base_unit' => $this->baseUnitA->id,
        ]);

        $this->brandA = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B2Brand_{$this->token}"]);
        $this->categoryA = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B2Cat_{$this->token}"]);
    }

    protected function tearDown(): void
    {
        $tenantIds = [$this->tenantAId, $this->tenantBId];

        $purchaseIds = DB::table('purchases')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        if (! empty($purchaseIds)) {
            DB::table('purchase_items')->whereIn('purchase_id', $purchaseIds)->delete();
            DB::table('purchases')->whereIn('id', $purchaseIds)->delete();
        }
        $adjIds = DB::table('adjustments')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        if (! empty($adjIds)) {
            DB::table('adjustment_items')->whereIn('adjustment_id', $adjIds)->delete();
            DB::table('adjustments')->whereIn('id', $adjIds)->delete();
        }

        DB::table('expenses')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('expense_categories')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('stock_movements')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('manage_stocks')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('products')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('main_products')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('units')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('base_units')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('product_categories')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('brands')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('payment_methods')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('suppliers')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('warehouses')->whereIn('tenant_id', $tenantIds)->delete();

        $userIds = array_filter([$this->adminA?->id, $this->limitedUserA?->id]);
        if (! empty($userIds)) {
            Subscription::whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            Store::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->delete();
            User::whereIn('id', $userIds)->forceDelete();
        }
        if ($this->noSupplierRole) {
            DB::table('role_has_permissions')->where('role_id', $this->noSupplierRole->id)->delete();
            $this->noSupplierRole->delete();
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

    /** C2-17 (Part 1): ProductRepository::storeProduct() respects purchase_status & logs typed purchase movement */
    public function test_c217_product_create_pending_adds_no_stock_and_received_adds_once_with_purchase_movement(): void
    {
        Sanctum::actingAs($this->adminA);
        $repo = app(\App\Repositories\ProductRepository::class);

        $mainPending = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2B2 Main Pend {$this->token}",
            'code' => "C2B2-MP-{$this->token}",
            'product_unit' => (string) $this->baseUnitA->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);

        // 1. Create product with PENDING purchase -> ZERO stock & ZERO stock_movements
        $prodPending = $repo->storeProduct([
            'main_product_id' => $mainPending->id,
            'name' => "C2B2 Prod Pend {$this->token}",
            'code' => "C2B2-PP-{$this->token}",
            'product_code' => "C2B2-SKP-{$this->token}",
            'product_category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 12.50,
            'product_price' => 25.00,
            'product_unit' => (string) $this->baseUnitA->id,
            'sale_unit' => (string) $this->unitA->id,
            'purchase_unit' => (string) $this->unitA->id,
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
            'purchase_warehouse_id' => $this->warehouseA1->id,
            'purchase_supplier_id' => $this->supplierA->id,
            'purchase_quantity' => 10,
            'purchase_status' => Purchase::PENDING,
            'purchase_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertNull(ManageStock::withoutGlobalScope('tenant')->where('product_id', $prodPending->id)->first());
        $this->assertEquals(0, StockMovement::withoutGlobalScope('tenant')->where('product_id', $prodPending->id)->count());

        // 2. Create product with RECEIVED purchase -> stock = 7 & 1 'purchase' movement linked to Purchase::class
        $mainRecv = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2B2 Main Recv {$this->token}",
            'code' => "C2B2-MR-{$this->token}",
            'product_unit' => (string) $this->baseUnitA->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);

        $prodRecv = $repo->storeProduct([
            'main_product_id' => $mainRecv->id,
            'name' => "C2B2 Prod Recv {$this->token}",
            'code' => "C2B2-PR-{$this->token}",
            'product_code' => "C2B2-SKR-{$this->token}",
            'product_category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 15.00,
            'product_price' => 30.00,
            'product_unit' => (string) $this->baseUnitA->id,
            'sale_unit' => (string) $this->unitA->id,
            'purchase_unit' => (string) $this->unitA->id,
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
            'purchase_warehouse_id' => $this->warehouseA1->id,
            'purchase_supplier_id' => $this->supplierA->id,
            'purchase_quantity' => 7,
            'purchase_status' => Purchase::RECEIVED,
            'purchase_date' => Carbon::today()->toDateString(),
        ]);

        $this->assertEquals(7.0, (float) ManageStock::withoutGlobalScope('tenant')->where('product_id', $prodRecv->id)->value('quantity'));
        $mv = StockMovement::withoutGlobalScope('tenant')->where('product_id', $prodRecv->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('purchase', $mv->type);
        $this->assertSame(Purchase::class, $mv->reference_type);
        $this->assertNotNull($mv->reference_id);
        $this->assertEquals(15.00, (float) $mv->unit_cost);
    }

    /** C2-17 (Part 2): ProductImport::collection() only adds stock for 'received' rows */
    public function test_c217_product_import_respects_purchase_status(): void
    {
        Sanctum::actingAs($this->adminA);

        $rows = new Collection([
            // Row 1: pending -> 0 stock
            [
                "ImpPend_{$this->token}", "IMP-P-{$this->token}", $this->categoryA->name, $this->brandA->name,
                'CODE128', 10, 20, $this->baseUnitA->name, $this->unitA->name, $this->unitA->name, 0, 0, 'exclusive', '',
                $this->warehouseA1->name, $this->supplierA->name, 9, 'pending', '', '',
            ],
            // Row 2: received -> +6 stock with 'purchase' movement
            [
                "ImpRecv_{$this->token}", "IMP-R-{$this->token}", $this->categoryA->name, $this->brandA->name,
                'CODE128', 14, 28, $this->baseUnitA->name, $this->unitA->name, $this->unitA->name, 0, 0, 'exclusive', '',
                $this->warehouseA1->name, $this->supplierA->name, 6, 'received', '', '',
            ],
        ]);

        (new ProductImport())->collection($rows);

        $pPend = Product::withoutGlobalScope('tenant')->where('code', "IMP-P-{$this->token}")->firstOrFail();
        $pRecv = Product::withoutGlobalScope('tenant')->where('code', "IMP-R-{$this->token}")->firstOrFail();

        $this->assertNull(ManageStock::withoutGlobalScope('tenant')->where('product_id', $pPend->id)->first());
        $this->assertEquals(0, StockMovement::withoutGlobalScope('tenant')->where('product_id', $pPend->id)->count());

        $this->assertEquals(6.0, (float) ManageStock::withoutGlobalScope('tenant')->where('product_id', $pRecv->id)->value('quantity'));
        $mv = StockMovement::withoutGlobalScope('tenant')->where('product_id', $pRecv->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('purchase', $mv->type);
        $this->assertSame(Purchase::class, $mv->reference_type);
    }

    /** C2-16: Product, MainProduct, and Warehouse deletion blocked when StockMovement or Adjustment exists (even with 0 stock) */
    public function test_c216_product_main_product_and_warehouse_delete_protects_audit_history(): void
    {
        Sanctum::actingAs($this->adminA);

        $main = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2B2 Main Var {$this->token}",
            'code' => "C2B2-MV-{$this->token}",
            'product_unit' => (string) $this->baseUnitA->id,
            'product_type' => MainProduct::VARIATION_PRODUCT,
        ]);
        $prodClean = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $main->id,
            'name' => "C2B2 Var1 {$this->token}",
            'code' => "C2B2-V1-{$this->token}",
            'product_code' => "C2B2-SV1-{$this->token}",
            'product_category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 5, 'product_price' => 10,
            'product_unit' => (string) $this->baseUnitA->id,
            'sale_unit' => (string) $this->unitA->id,
            'purchase_unit' => (string) $this->unitA->id,
            'barcode_symbol' => Product::CODE128, 'tax_type' => 1, 'order_tax' => 0,
        ]);
        $prodWithLedger = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $main->id,
            'name' => "C2B2 Var2 {$this->token}",
            'code' => "C2B2-V2-{$this->token}",
            'product_code' => "C2B2-SV2-{$this->token}",
            'product_category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 5, 'product_price' => 10,
            'product_unit' => (string) $this->baseUnitA->id,
            'sale_unit' => (string) $this->unitA->id,
            'purchase_unit' => (string) $this->unitA->id,
            'barcode_symbol' => Product::CODE128, 'tax_type' => 1, 'order_tax' => 0,
        ]);

        // Record a historical stock movement (even with final quantity 0!) on prodWithLedger @ warehouseA1
        StockMovement::create([
            'tenant_id' => $this->tenantAId,
            'warehouse_id' => $this->warehouseA1->id,
            'product_id' => $prodWithLedger->id,
            'type' => 'adjustment_add',
            'quantity_change' => 5,
            'quantity_before' => 0,
            'quantity_after' => 5,
            'user_id' => $this->adminA->id,
        ]);

        // 1. Single product delete blocked & stock_movement preserved
        $delProd = $this->deleteJson('/api/products/' . $prodWithLedger->id);
        $this->assertFalse((bool) $delProd->json('success'));
        $this->assertDatabaseHas('products', ['id' => $prodWithLedger->id]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $prodWithLedger->id]);

        // 2. MainProduct delete blocked AND variation #1 (prodClean) is NOT deleted (atomic pre-check + rollback)
        $delMain = $this->deleteJson('/api/main-products/' . $main->id);
        $this->assertFalse((bool) $delMain->json('success'));
        $this->assertDatabaseHas('main_products', ['id' => $main->id]);
        $this->assertDatabaseHas('products', ['id' => $prodClean->id]);
        $this->assertDatabaseHas('products', ['id' => $prodWithLedger->id]);

        // 3. WarehouseA1 delete blocked because of StockMovement; unused WarehouseA2 delete succeeds
        $delWh1 = $this->deleteJson('/api/warehouses/' . $this->warehouseA1->id);
        $this->assertFalse((bool) $delWh1->json('success'));
        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouseA1->id]);

        $delWh2 = $this->deleteJson('/api/warehouses/' . $this->warehouseA2->id);
        $delWh2->assertStatus(200);
        $this->assertDatabaseMissing('warehouses', ['id' => $this->warehouseA2->id]);
    }

    /** C2-18 & C2-28: import-suppliers RBAC + atomic rollback + TLD/phone/CSV-formula handling */
    public function test_c218_c228_supplier_import_rbac_atomicity_and_sanitization(): void
    {
        // 1. User without manage_suppliers gets 403
        Sanctum::actingAs($this->limitedUserA);
        $csvBad = "name,email,phone,country,city,address\nValidSup,valid.{$this->token}@acme.company,+1-555-0100,US,NY,123 Main\nBadSup,not-an-email,+1-555-0101,US,NY,456 Main\n";
        $file1 = UploadedFile::fake()->createWithContent('suppliers_bad.csv', $csvBad);
        $this->post('/api/import-suppliers', ['file' => $file1], ['Accept' => 'application/json'])->assertStatus(403);

        // 2. Admin with 1 valid row + 1 invalid row -> atomic rollback (ZERO rows committed!)
        Sanctum::actingAs($this->adminA);
        $file2 = UploadedFile::fake()->createWithContent('suppliers_bad.csv', $csvBad);
        $resFail = $this->post('/api/import-suppliers', ['file' => $file2], ['Accept' => 'application/json']);
        $this->assertSame(422, $resFail->status());
        $this->assertDatabaseMissing('suppliers', ['email' => "valid.{$this->token}@acme.company"]);

        // 3. Valid CSV with modern .company TLD, international phone, and formula prefix -> succeeds & sanitizes
        $csvGood = "name,email,phone,country,city,address\n=CMD('calc'),good.{$this->token}@acme.company,+1-555-0199,US,NY,789 Oak\n";
        $file3 = UploadedFile::fake()->createWithContent('suppliers_good.csv', $csvGood);
        $this->post('/api/import-suppliers', ['file' => $file3], ['Accept' => 'application/json'])->assertStatus(200);

        $imported = Supplier::withoutGlobalScope('tenant')->where('email', "good.{$this->token}@acme.company")->first();
        $this->assertNotNull($imported);
        $this->assertSame("'=CMD('calc')", $imported->name);
        $this->assertSame('+1-555-0199', $imported->phone);
    }

    /** C2-35: Store Admin cache-clear never flushes other tenants' cache keys */
    public function test_c235_store_admin_cache_clear_is_tenant_safe(): void
    {
        $otherTenantKey = 'sale_idempotency:' . $this->tenantBId . ':test_key';
        Cache::put($otherTenantKey, ['status' => 'completed', 'sale_id' => 999], 300);

        Sanctum::actingAs($this->adminA);
        $this->getJson('/api/cache-clear')->assertStatus(200);

        // Tenant B's idempotency cache MUST still exist!
        $this->assertNotNull(Cache::get($otherTenantKey));

        // Cleanup
        Cache::forget($otherTenantKey);
    }

    /** C2-33: Purchase validation rejects invalid status, empty items, and negative costs/discounts */
    public function test_c233_purchase_validation_hardening(): void
    {
        Sanctum::actingAs($this->adminA);

        $base = [
            'date' => Carbon::today()->toDateString(),
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => 99, // invalid status
            'purchase_items' => [], // empty items
        ];
        $this->postJson('/api/purchases', $base)->assertStatus(422);

        // Negative product_cost rejected
        $prod = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $this->createWithoutTenantScope(MainProduct::class, [
                'tenant_id' => $this->tenantAId, 'name' => "M{$this->token}", 'code' => "CM{$this->token}",
                'product_unit' => (string) $this->baseUnitA->id, 'product_type' => MainProduct::SINGLE_PRODUCT,
            ])->id,
            'name' => "P{$this->token}", 'code' => "CP{$this->token}", 'product_code' => "CSP{$this->token}",
            'product_category_id' => $this->categoryA->id, 'brand_id' => $this->brandA->id,
            'product_cost' => 10, 'product_price' => 20,
            'product_unit' => (string) $this->baseUnitA->id,
            'sale_unit' => (string) $this->unitA->id,
            'purchase_unit' => (string) $this->unitA->id,
            'barcode_symbol' => Product::CODE128, 'tax_type' => 1, 'order_tax' => 0,
        ]);

        $negCost = [
            'date' => Carbon::today()->toDateString(),
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0, 'discount' => 0, 'shipping' => 0, 'status' => Purchase::PENDING,
            'purchase_items' => [[
                'product_id' => $prod->id, 'product_cost' => -5, 'net_unit_cost' => -5,
                'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                'purchase_unit' => $this->unitA->id, 'quantity' => 2, 'sub_total' => -10,
            ]],
        ];
        $this->postJson('/api/purchases', $negCost)->assertStatus(422);
    }

    /** C2-16 Microfix: Warehouse linked ONLY to an Expense cannot be deleted; unused warehouse can be deleted */
    public function test_c216_warehouse_deletion_blocked_when_only_expense_exists_and_unused_warehouse_deleted(): void
    {
        Sanctum::actingAs($this->adminA);

        $expCat = $this->createWithoutTenantScope(\App\Models\ExpenseCategory::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2B2ExpCat_{$this->token}",
        ]);

        // Link ONLY an Expense record to warehouseA1 (zero sales, purchases, stock, or movements)
        $expense = $this->createWithoutTenantScope(\App\Models\Expense::class, [
            'tenant_id' => $this->tenantAId,
            'date' => Carbon::today()->toDateString(),
            'warehouse_id' => $this->warehouseA1->id,
            'expense_category_id' => $expCat->id,
            'amount' => 45.00,
            'title' => 'Warehouse Utility Bill',
            'details' => 'Microfix test expense',
        ]);

        $delLinked = $this->deleteJson('/api/warehouses/' . $this->warehouseA1->id);
        $this->assertFalse((bool) $delLinked->json('success'));
        $this->assertDatabaseHas('warehouses', ['id' => $this->warehouseA1->id]);
        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);

        // Unused warehouseA2 can still be deleted
        $delUnused = $this->deleteJson('/api/warehouses/' . $this->warehouseA2->id);
        $delUnused->assertStatus(200);
        $this->assertDatabaseMissing('warehouses', ['id' => $this->warehouseA2->id]);
    }
}
