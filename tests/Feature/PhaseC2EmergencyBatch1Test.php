<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use App\Services\StockMovementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class PhaseC2EmergencyBatch1Test extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $plan = null;
    private ?Warehouse $warehouseA1 = null;
    private ?Warehouse $warehouseA2 = null;
    private ?Warehouse $warehouseB1 = null;
    private ?Customer $customerA = null;
    private ?Supplier $supplierA = null;
    private ?Supplier $supplierAUnused = null;
    private ?Supplier $supplierB = null;
    private ?BaseUnit $baseUnitA = null;
    private ?BaseUnit $baseUnitB = null;
    private ?Unit $unitA1 = null;
    private ?Unit $unitA2 = null;
    private ?Unit $unitB1 = null;
    private ?Product $productA = null;
    private ?Product $productB = null;
    private ?PaymentMethod $pmA = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c2b1_tA_' . $this->token;
        $this->tenantBId = 'c2b1_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c2b1_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c2b1_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C2AdminA_{$this->token}", "c2b1.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("C2AdminB_{$this->token}", "c2b1.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);

        $this->storeA = Store::create(['name' => "C2 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "C2 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "C2 Plan {$this->token}",
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
                'plan_frequency' => Plan::MONTHLY,
                'start_date' => Carbon::now()->subDay(),
                'end_date' => Carbon::now()->addYear(),
                'status' => Subscription::ACTIVE,
                'no_of_stores' => 5,
            ]);
        }

        $this->warehouseA1 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2 WH A1 {$this->token}",
            'phone' => '1111111111',
            'country' => 'US',
            'city' => 'NY',
            'email' => "wha1.{$this->token}@test.local",
        ]);
        $this->warehouseA2 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2 WH A2 {$this->token}",
            'phone' => '2222222222',
            'country' => 'US',
            'city' => 'LA',
            'email' => "wha2.{$this->token}@test.local",
        ]);
        $this->warehouseB1 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C2 WH B1 {$this->token}",
            'phone' => '3333333333',
            'country' => 'US',
            'city' => 'CHI',
            'email' => "whb1.{$this->token}@test.local",
        ]);

        $this->customerA = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2 Cust A {$this->token}",
            'email' => "ca.{$this->token}@test.local",
            'phone' => '4444444444',
            'country' => 'US',
            'city' => 'NY',
            'address' => '1 Main St',
        ]);

        $this->supplierA = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2 Sup A {$this->token}",
            'email' => "sa.{$this->token}@test.local",
            'phone' => '5555555555',
            'country' => 'US',
            'city' => 'NY',
            'address' => '10 Supply Ave',
        ]);
        $this->supplierAUnused = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2 Sup A Unused {$this->token}",
            'email' => "sa.unused.{$this->token}@test.local",
            'phone' => '5555555556',
            'country' => 'US',
            'city' => 'NY',
            'address' => '11 Supply Ave',
        ]);
        $this->supplierB = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C2 Sup B {$this->token}",
            'email' => "sb.{$this->token}@test.local",
            'phone' => '6666666666',
            'country' => 'US',
            'city' => 'CHI',
            'address' => '20 Supply Ave',
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
        $this->baseUnitB = $this->createWithoutTenantScope(BaseUnit::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "PieceB_{$this->token}",
        ]);

        $this->unitA1 = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "UnitA1_{$this->token}",
            'short_name' => 'pc',
            'base_unit' => $this->baseUnitA->id,
        ]);
        $this->unitA2 = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "UnitA2Box_{$this->token}",
            'short_name' => 'box',
            'base_unit' => $this->baseUnitA->id,
        ]);
        $this->unitB1 = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "UnitB1_{$this->token}",
            'short_name' => 'pcB',
            'base_unit' => $this->baseUnitB->id,
        ]);

        $brandA = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantAId, 'name' => "C2 Brand A {$this->token}"]);
        $brandB = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantBId, 'name' => "C2 Brand B {$this->token}"]);
        $catA = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantAId, 'name' => "C2 Cat A {$this->token}"]);
        $catB = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantBId, 'name' => "C2 Cat B {$this->token}"]);

        $mainA = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C2 Main A {$this->token}",
            'code' => "C2-MA-{$this->token}",
            'product_unit' => (string) $this->baseUnitA->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);
        $mainB = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C2 Main B {$this->token}",
            'code' => "C2-MB-{$this->token}",
            'product_unit' => (string) $this->baseUnitB->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);

        $this->productA = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $mainA->id,
            'name' => "C2 Product A {$this->token}",
            'code' => "C2-PA-{$this->token}",
            'product_code' => "C2-SKA-{$this->token}",
            'product_category_id' => $catA->id,
            'brand_id' => $brandA->id,
            'product_cost' => 10.00,
            'product_price' => 25.00,
            'product_unit' => (string) $this->baseUnitA->id,
            'sale_unit' => (string) $this->unitA1->id,
            'purchase_unit' => (string) $this->unitA1->id,
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);
        $this->productB = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantBId,
            'main_product_id' => $mainB->id,
            'name' => "C2 Product B {$this->token}",
            'code' => "C2-PB-{$this->token}",
            'product_code' => "C2-SKB-{$this->token}",
            'product_category_id' => $catB->id,
            'brand_id' => $brandB->id,
            'product_cost' => 5.00,
            'product_price' => 15.00,
            'product_unit' => (string) $this->baseUnitB->id,
            'sale_unit' => (string) $this->unitB1->id,
            'purchase_unit' => (string) $this->unitB1->id,
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);

        $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantAId,
            'warehouse_id' => $this->warehouseA1->id,
            'product_id' => $this->productA->id,
            'quantity' => 20,
            'alert' => 0,
        ]);
        $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantBId,
            'warehouse_id' => $this->warehouseB1->id,
            'product_id' => $this->productB->id,
            'quantity' => 20,
            'alert' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        $tenantIds = [$this->tenantAId, $this->tenantBId];

        $saleIds = DB::table('sales')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        if (! empty($saleIds)) {
            $srIds = DB::table('sales_return')->whereIn('sale_id', $saleIds)->pluck('id')->all();
            if (! empty($srIds)) {
                DB::table('sale_return_items')->whereIn('sale_return_id', $srIds)->delete();
                DB::table('sales_return')->whereIn('id', $srIds)->delete();
            }
            DB::table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
            DB::table('sale_items')->whereIn('sale_id', $saleIds)->delete();
            DB::table('sales')->whereIn('id', $saleIds)->delete();
        }

        $prIds = DB::table('purchases_return')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        if (! empty($prIds)) {
            DB::table('purchases_return_items')->whereIn('purchase_return_id', $prIds)->delete();
            DB::table('purchases_return')->whereIn('id', $prIds)->delete();
        }

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

        $trIds = DB::table('transfers')->whereIn('tenant_id', $tenantIds)->pluck('id')->all();
        if (! empty($trIds)) {
            DB::table('transfer_items')->whereIn('transfer_id', $trIds)->delete();
            DB::table('transfers')->whereIn('id', $trIds)->delete();
        }

        DB::table('stock_movements')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('manage_stocks')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('products')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('main_products')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('units')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('base_units')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('product_categories')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('brands')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('payment_methods')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('customers')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('suppliers')->whereIn('tenant_id', $tenantIds)->delete();
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

    private function purchasePayload(int $status = Purchase::RECEIVED, float $qty = 5, ?int $unitId = null): array
    {
        $unit = $unitId ?? $this->unitA1->id;

        return [
            'date' => Carbon::today()->toDateString(),
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 10.00 * $qty,
            'received_amount' => 0,
            'paid_amount' => 0,
            'partial_amount' => 0,
            'payment_type' => $this->pmA->id,
            'payment_status' => 2,
            'status' => $status,
            'notes' => 'C2 Purchase Test',
            'purchase_items' => [
                [
                    'product_id' => $this->productA->id,
                    'product_cost' => 10.00,
                    'net_unit_cost' => 10.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'purchase_unit' => $unit,
                    'quantity' => $qty,
                    'sub_total' => 10.00 * $qty,
                ],
            ],
        ];
    }

    private function salePayload(float $qty = 5, ?int $warehouseId = null, ?int $unitId = null): array
    {
        $wh = $warehouseId ?? $this->warehouseA1->id;
        $unit = $unitId ?? $this->unitA1->id;
        $total = 25.00 * $qty;

        return [
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $wh,
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
                ['amount' => $total, 'payment_type' => ['value' => $this->pmA->id]],
            ],
            'sale_items' => [
                [
                    'product_id' => $this->productA->id,
                    'product_price' => 25.00,
                    'net_unit_price' => 25.00,
                    'tax_type' => Sale::EXCLUSIVE,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => Sale::FIXED,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $unit,
                    'quantity' => $qty,
                    'sub_total' => $total,
                ],
            ],
        ];
    }

    /** E1: Supplier deletion blocked when linked to purchases or purchase returns; unused supplier allowed; cross-tenant blocked */
    public function test_e1_supplier_deletion_protection_blocks_in_use_and_preserves_history(): void
    {
        Sanctum::actingAs($this->adminA);

        // Create a purchase linked to supplierA
        $createRes = $this->postJson('/api/purchases', $this->purchasePayload(Purchase::RECEIVED, 5));
        $createRes->assertStatus(201);
        $purchaseId = (int) $createRes->json('data.id');

        // Attempt to delete supplierA (has purchase) -> MUST be rejected and purchase MUST remain intact
        $delRes = $this->deleteJson('/api/suppliers/' . $this->supplierA->id);
        $this->assertFalse((bool) $delRes->json('success'));
        $this->assertDatabaseHas('suppliers', ['id' => $this->supplierA->id]);
        $this->assertDatabaseHas('purchases', ['id' => $purchaseId]);

        // Cross-tenant supplier ID cannot be deleted by tenant A
        $crossRes = $this->deleteJson('/api/suppliers/' . $this->supplierB->id);
        $this->assertTrue(in_array($crossRes->status(), [404, 422], true));
        $this->assertDatabaseHas('suppliers', ['id' => $this->supplierB->id]);

        // Unused supplier deletion succeeds
        $okRes = $this->deleteJson('/api/suppliers/' . $this->supplierAUnused->id);
        $okRes->assertStatus(200);
        $this->assertDatabaseMissing('suppliers', ['id' => $this->supplierAUnused->id]);
    }

    /** E2: Transfer reversal after destination stock consumption must fail safely without phantom stock */
    public function test_e2_transfer_reversal_blocked_when_destination_stock_consumed_and_valid_reversal_works(): void
    {
        Sanctum::actingAs($this->adminA);

        // Transfer 10 units from WH A1 (20 -> 10) to WH A2 (0 -> 10)
        $trRes = $this->postJson('/api/transfers', [
            'date' => Carbon::today()->toDateString(),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseA2->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 250.00,
            'status' => Transfer::COMPLETED,
            'transfer_items' => [
                [
                    'product_id' => $this->productA->id,
                    'product_price' => 25.00,
                    'net_unit_price' => 25.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'quantity' => 10,
                    'sub_total' => 250.00,
                ],
            ],
        ]);
        $trRes->assertStatus(201);
        $transferId = (int) $trRes->json('data.id');

        $this->assertEquals(10.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
        $this->assertEquals(10.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA2->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Sell 7 units from WH A2 (10 -> 3)
        $saleRes = $this->postJson('/api/sales', $this->salePayload(7, $this->warehouseA2->id));
        $saleRes->assertStatus(201);
        $this->assertEquals(3.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA2->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Attempt to delete transfer (needs 10 from WH A2, only 3 left) -> MUST FAIL 422!
        $delFail = $this->deleteJson('/api/transfers/' . $transferId);
        $delFail->assertStatus(422);

        // Verify ZERO phantom stock: WH A1 still 10, WH A2 still 3, transfer still exists
        $this->assertEquals(10.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
        $this->assertEquals(3.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA2->id)->where('product_id', $this->productA->id)->value('quantity'));
        $this->assertDatabaseHas('transfers', ['id' => $transferId]);

        // Valid reversal when destination has full stock: transfer 2 units A1 (10->8) to A2 (3->5), then delete -> A1=10, A2=3
        $tr2Res = $this->postJson('/api/transfers', [
            'date' => Carbon::today()->toDateString(),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseA2->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 50.00,
            'status' => Transfer::COMPLETED,
            'transfer_items' => [
                [
                    'product_id' => $this->productA->id,
                    'product_price' => 25.00,
                    'net_unit_price' => 25.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'quantity' => 2,
                    'sub_total' => 50.00,
                ],
            ],
        ]);
        $tr2Res->assertStatus(201);
        $transfer2Id = (int) $tr2Res->json('data.id');

        $delOk = $this->deleteJson('/api/transfers/' . $transfer2Id);
        $delOk->assertStatus(200);
        $this->assertEquals(10.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
        $this->assertEquals(3.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA2->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Reversal movements logged properly (not generic 'manual')
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'transfer_reversal_out',
            'reference_type' => Transfer::class,
            'reference_id' => $transfer2Id,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'transfer_reversal_in',
            'reference_type' => Transfer::class,
            'reference_id' => $transfer2Id,
        ]);

        // Duplicate reversal (second delete) rejected with 404 without double-reversing
        $delDup = $this->deleteJson('/api/transfers/' . $transfer2Id);
        $delDup->assertStatus(404);
        $this->assertEquals(10.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
    }

    /** E3 & E4: StockMovementService atomic operations, rollback on movement failure, and no silent negative clamping */
    public function test_e3_e4_stock_movement_service_atomic_rollback_and_no_silent_clamping(): void
    {
        Sanctum::actingAs($this->adminA);
        /** @var StockMovementService $service */
        $service = app(StockMovementService::class);

        // 1. Negative stock deduction via service or manageStock() throws 422 (never clamps to 0)
        try {
            $service->decrease($this->warehouseA1->id, $this->productA->id, 25.0, 'sale', Sale::class, 999);
            $this->fail('Expected UnprocessableEntityHttpException for insufficient stock.');
        } catch (UnprocessableEntityHttpException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertEquals(20.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // 2. Movement insertion failure rolls back manage_stocks update atomically
        $listenerFailed = false;
        StockMovement::creating(function () use (&$listenerFailed) {
            if (! $listenerFailed) {
                $listenerFailed = true;
                throw new \RuntimeException('Simulated ledger insert failure');
            }
        });

        try {
            DB::transaction(function () use ($service) {
                $service->decrease($this->warehouseA1->id, $this->productA->id, 5.0, 'sale', Sale::class, 998);
            });
            $this->fail('Expected RuntimeException from simulated movement failure.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Simulated ledger insert failure', $e->getMessage());
        } finally {
            StockMovement::flushEventListeners();
            StockMovement::boot();
        }

        // Stock MUST still be 20.0 (rolled back)
        $this->assertEquals(20.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // 3. Fractional quantity supported & ledger invariant before + change == after holds
        DB::transaction(function () use ($service) {
            $service->decrease($this->warehouseA1->id, $this->productA->id, 2.5, 'sale', Sale::class, 997);
        });
        $this->assertEquals(17.5, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
        $mv = StockMovement::withoutGlobalScope('tenant')->where('reference_id', 997)->first();
        $this->assertNotNull($mv);
        $this->assertEquals(20.0, (float) $mv->quantity_before);
        $this->assertEquals(-2.5, (float) $mv->quantity_change);
        $this->assertEquals(17.5, (float) $mv->quantity_after);
    }

    /** E5: Purchase lifecycle (PENDING no stock, edit PENDING no stock, delete PENDING no deduction, PENDING->RECEIVED once, repeat RECEIVED no duplicate) */
    public function test_e5_purchase_lifecycle_and_duplicate_receive_protection(): void
    {
        Sanctum::actingAs($this->adminA);

        // 1. Create PENDING purchase -> stock stays 20
        $pRes = $this->postJson('/api/purchases', $this->purchasePayload(Purchase::PENDING, 6));
        $pRes->assertStatus(201);
        $purchaseId = (int) $pRes->json('data.id');
        $purchaseItemId = (int) DB::table('purchase_items')->where('purchase_id', $purchaseId)->value('id');
        $this->assertEquals(20.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // 2. Edit PENDING purchase (qty 6 -> 8, still PENDING) -> stock STILL 20
        $editPending = $this->purchasePayload(Purchase::PENDING, 8);
        $editPending['purchase_items'][0]['purchase_item_id'] = $purchaseItemId;
        $this->putJson('/api/purchases/' . $purchaseId, $editPending)->assertStatus(200);
        $this->assertEquals(20.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // 3. Transition PENDING -> RECEIVED -> stock increases ONCE (20 -> 28)
        $receivePayload = $this->purchasePayload(Purchase::RECEIVED, 8);
        $receivePayload['purchase_items'][0]['purchase_item_id'] = $purchaseItemId;
        $this->putJson('/api/purchases/' . $purchaseId, $receivePayload)->assertStatus(200);
        $this->assertEquals(28.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // 4. Repeat RECEIVED update with same qty -> stock MUST stay 28 (no duplicate receive!)
        $this->putJson('/api/purchases/' . $purchaseId, $receivePayload)->assertStatus(200);
        $this->assertEquals(28.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // 5. Delete PENDING purchase -> does NOT deduct stock
        $p2Res = $this->postJson('/api/purchases', $this->purchasePayload(Purchase::PENDING, 5));
        $p2Res->assertStatus(201);
        $p2Id = (int) $p2Res->json('data.id');
        $this->deleteJson('/api/purchases/' . $p2Id)->assertStatus(200);
        $this->assertEquals(28.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // 6. Delete RECEIVED purchase -> reverses stock cleanly (28 -> 20)
        $this->deleteJson('/api/purchases/' . $purchaseId)->assertStatus(200);
        $this->assertEquals(20.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
    }

    /** E6: Cumulative Sales Returns & Purchase Returns + negative/zero quantity rejection */
    public function test_e6_cumulative_sale_and_purchase_return_protection(): void
    {
        Sanctum::actingAs($this->adminA);

        // Create a sale of 5 units (stock 20 -> 15)
        $saleRes = $this->postJson('/api/sales', $this->salePayload(5));
        $saleRes->assertStatus(201);
        $saleId = (int) $saleRes->json('data.id');

        $makeSaleReturnPayload = fn (float $qty) => [
            'sale_id' => $saleId,
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 25.00 * $qty,
            'paid_amount' => 0,
            'payment_type' => $this->pmA->id,
            'status' => 1,
            'note' => 'C2 Sale Return',
            'sale_return_items' => [
                [
                    'product_id' => $this->productA->id,
                    'product_price' => 25.00,
                    'net_unit_price' => 25.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $this->unitA1->id,
                    'quantity' => $qty,
                    'sub_total' => 25.00 * $qty,
                ],
            ],
        ];

        // Negative return rejected
        $this->postJson('/api/sales-return', $makeSaleReturnPayload(-2))->assertStatus(422);

        // First partial return of 3 (remaining 2) succeeds -> stock 15 -> 18
        $this->postJson('/api/sales-return', $makeSaleReturnPayload(3))->assertStatus(201);
        $this->assertEquals(18.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Second return of 3 (exceeds remaining 2) MUST FAIL 422! Stock stays 18
        $this->postJson('/api/sales-return', $makeSaleReturnPayload(3))->assertStatus(422);
        $this->assertEquals(18.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Duplicate product rows in single request (2 + 1 = 3 > remaining 2) MUST FAIL 422!
        $dupPayload = $makeSaleReturnPayload(2);
        $dupPayload['sale_return_items'][] = $dupPayload['sale_return_items'][0];
        $this->postJson('/api/sales-return', $dupPayload)->assertStatus(422);

        // Return exact remaining 2 succeeds -> stock 18 -> 20
        $this->postJson('/api/sales-return', $makeSaleReturnPayload(2))->assertStatus(201);
        $this->assertEquals(20.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Purchase Returns: create RECEIVED purchase of 5 (stock 20 -> 25)
        $pRes = $this->postJson('/api/purchases', $this->purchasePayload(Purchase::RECEIVED, 5));
        $pRes->assertStatus(201);
        $purchaseId = (int) $pRes->json('data.id');

        $makePurchaseReturnPayload = fn (float $qty) => [
            'purchase_id' => $purchaseId,
            'date' => Carbon::today()->toDateString(),
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 10.00 * $qty,
            'received_amount' => 0,
            'paid_amount' => 0,
            'payment_type' => $this->pmA->id,
            'payment_status' => 2,
            'status' => 1,
            'notes' => 'C2 Purchase Return',
            'purchase_return_items' => [
                [
                    'product_id' => $this->productA->id,
                    'product_cost' => 10.00,
                    'net_unit_cost' => 10.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'purchase_unit' => $this->unitA1->id,
                    'quantity' => $qty,
                    'sub_total' => 10.00 * $qty,
                ],
            ],
        ];

        // Negative purchase return rejected
        $this->postJson('/api/purchases-return', $makePurchaseReturnPayload(-3))->assertStatus(422);

        // Return 3 of 5 -> succeeds (stock 25 -> 22)
        $this->postJson('/api/purchases-return', $makePurchaseReturnPayload(3))->assertStatus(201);
        $this->assertEquals(22.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Second return of 3 (exceeds remaining 2) -> MUST FAIL 422! Stock stays 22
        $this->postJson('/api/purchases-return', $makePurchaseReturnPayload(3))->assertStatus(422);
        $this->assertEquals(22.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
    }

    /** E4 & 4.5: Adjustment rollback and negative stock prevention on delete */
    public function test_e4_adjustment_negative_stock_and_delete_rollback_protection(): void
    {
        Sanctum::actingAs($this->adminA);

        // Add +10 via adjustment (stock 20 -> 30)
        $adjRes = $this->postJson('/api/adjustments', [
            'date' => Carbon::today()->toDateString(),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => 10,
                    'method_type' => AdjustmentItem::METHOD_ADDITION,
                ],
            ],
        ]);
        $adjRes->assertStatus(201);
        $adjId = (int) $adjRes->json('data.id');
        $this->assertEquals(30.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Sell 25 units (stock 30 -> 5)
        $this->postJson('/api/sales', $this->salePayload(25))->assertStatus(201);
        $this->assertEquals(5.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));

        // Attempt to delete +10 adjustment when only 5 remain -> MUST FAIL 422 and roll back cleanly!
        $this->deleteJson('/api/adjustments/' . $adjId)->assertStatus(422);
        $this->assertEquals(5.0, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA->id)->value('quantity'));
        $this->assertDatabaseHas('adjustments', ['id' => $adjId]);

        // Negative adjustment quantity rejected with 422
        $this->postJson('/api/adjustments', [
            'date' => Carbon::today()->toDateString(),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'product_id' => $this->productA->id,
                    'quantity' => -5,
                    'method_type' => AdjustmentItem::METHOD_ADDITION,
                ],
            ],
        ])->assertStatus(422);
    }

    /** E7: Cross-tenant transfer product_id and warehouse_id rejected */
    public function test_e7_cross_tenant_transfer_product_and_warehouse_rejected(): void
    {
        Sanctum::actingAs($this->adminA);

        // Foreign-tenant product_id rejected with 422
        $resProd = $this->postJson('/api/transfers', [
            'date' => Carbon::today()->toDateString(),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseA2->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 15.00,
            'status' => Transfer::COMPLETED,
            'transfer_items' => [
                [
                    'product_id' => $this->productB->id,
                    'product_price' => 15.00,
                    'net_unit_price' => 15.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'quantity' => 1,
                    'sub_total' => 15.00,
                ],
            ],
        ]);
        $resProd->assertStatus(422);

        // Foreign-tenant warehouse_id rejected with 422
        $resWh = $this->postJson('/api/transfers', [
            'date' => Carbon::today()->toDateString(),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseB1->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 25.00,
            'status' => Transfer::COMPLETED,
            'transfer_items' => [
                [
                    'product_id' => $this->productA->id,
                    'product_price' => 25.00,
                    'net_unit_price' => 25.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'quantity' => 1,
                    'sub_total' => 25.00,
                ],
            ],
        ]);
        $resWh->assertStatus(422);
    }

    /** E8: Unsupported non-1:1 unit conversion and cross-tenant unit IDs rejected safely; valid 1:1 works */
    public function test_e8_unit_conversion_safety_blocks_mixed_and_cross_tenant_units(): void
    {
        Sanctum::actingAs($this->adminA);

        // Valid 1:1 sale (unitA1 == productA.sale_unit) succeeds
        $this->postJson('/api/sales', $this->salePayload(1, $this->warehouseA1->id, $this->unitA1->id))->assertStatus(201);

        // Unsupported mixed-unit sale (unitA2 'box' != productA.sale_unit 'pc') rejected 422
        $this->postJson('/api/sales', $this->salePayload(1, $this->warehouseA1->id, $this->unitA2->id))->assertStatus(422);

        // Cross-tenant unit ID (unitB1) rejected 422
        $this->postJson('/api/sales', $this->salePayload(1, $this->warehouseA1->id, $this->unitB1->id))->assertStatus(422);

        // Unsupported mixed-unit purchase (unitA2 'box' != productA.purchase_unit 'pc') rejected 422
        $this->postJson('/api/purchases', $this->purchasePayload(Purchase::RECEIVED, 2, $this->unitA2->id))->assertStatus(422);

        // Creating a Unit referencing a foreign-tenant BaseUnit rejected 422
        $this->postJson('/api/units', [
            'name' => 'CrossTenantUnit_' . $this->token,
            'short_name' => 'ctu',
            'base_unit' => $this->baseUnitB->id,
        ])->assertStatus(422);
    }
}
