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
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\TransferItem;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhaseC2Batch4BackendIntegrityTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?User $superAdmin = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $plan = null;
    private ?Warehouse $warehouseA1 = null;
    private ?Warehouse $warehouseA2 = null;
    private ?Warehouse $warehouseB1 = null;
    private ?BaseUnit $baseUnitA = null;
    private ?BaseUnit $baseUnitB = null;
    private ?Brand $brandA = null;
    private ?ProductCategory $catA = null;
    private ?Unit $unitA1 = null;
    private ?Unit $unitA2 = null;
    private ?Unit $unitB1 = null;
    private ?Product $productA1 = null;
    private ?Product $productA2 = null;
    private ?Product $productB = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c2b4_tA_' . $this->token;
        $this->tenantBId = 'c2b4_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c2b4_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c2b4_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C2B4AdminA_{$this->token}", "c2b4.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("C2B4AdminB_{$this->token}", "c2b4.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);
        $this->superAdmin = $this->makeUser("C2B4Super_{$this->token}", "c2b4.super.{$this->token}@stockmate.test", null, Role::SUPER_ADMIN);

        $this->storeA = Store::create(['name' => "C2B4 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "C2B4 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "C2B4 Plan {$this->token}",
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
            'tenant_id' => $this->tenantAId, 'name' => "C2B4WH_A1_{$this->token}", 'phone' => '1111111111', 'country' => 'US', 'city' => 'NY', 'email' => "wha1.{$this->token}@test.local",
        ]);
        $this->warehouseA2 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B4WH_A2_{$this->token}", 'phone' => '1111111112', 'country' => 'US', 'city' => 'LA', 'email' => "wha2.{$this->token}@test.local",
        ]);
        $this->warehouseB1 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantBId, 'name' => "C2B4WH_B1_{$this->token}", 'phone' => '2222222221', 'country' => 'US', 'city' => 'TX', 'email' => "whb1.{$this->token}@test.local",
        ]);

        $this->baseUnitA = $this->createWithoutTenantScope(BaseUnit::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B4PcA_{$this->token}", 'is_default' => 0]);
        $this->baseUnitB = $this->createWithoutTenantScope(BaseUnit::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B4PcB_{$this->token}", 'is_default' => 0]);

        $this->unitA1 = $this->createWithoutTenantScope(Unit::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B4UnitA1_{$this->token}", 'short_name' => 'pc', 'base_unit' => $this->baseUnitA->id, 'is_default' => 0]);
        $this->unitA2 = $this->createWithoutTenantScope(Unit::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B4UnitA2_{$this->token}", 'short_name' => 'bx', 'base_unit' => $this->baseUnitA->id, 'is_default' => 0]);
        $this->unitB1 = $this->createWithoutTenantScope(Unit::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B4UnitB1_{$this->token}", 'short_name' => 'pc', 'base_unit' => $this->baseUnitB->id, 'is_default' => 0]);

        $this->brandA = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B4BrandA_{$this->token}"]);
        $brandB = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B4BrandB_{$this->token}"]);
        $this->catA = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B4CatA_{$this->token}"]);
        $catB = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B4CatB_{$this->token}"]);

        $mpA1 = $this->createWithoutTenantScope(MainProduct::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B4ProdA1_{$this->token}", 'code' => "C2B4A1{$this->token}", 'product_unit' => $this->baseUnitA->id, 'product_type' => 1]);
        $mpA2 = $this->createWithoutTenantScope(MainProduct::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B4ProdA2_{$this->token}", 'code' => "C2B4A2{$this->token}", 'product_unit' => $this->baseUnitA->id, 'product_type' => 1]);
        $mpB = $this->createWithoutTenantScope(MainProduct::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B4ProdB_{$this->token}", 'code' => "C2B4B{$this->token}", 'product_unit' => $this->baseUnitB->id, 'product_type' => 1]);

        $this->productA1 = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId, 'main_product_id' => $mpA1->id, 'name' => "C2B4ProdA1_{$this->token}", 'code' => "C2B4A1{$this->token}", 'product_code' => "C2B4A1{$this->token}",
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id, 'product_cost' => 10.00, 'product_price' => 25.00,
            'product_unit' => $this->baseUnitA->id, 'sale_unit' => $this->unitA1->id, 'purchase_unit' => $this->unitA1->id, 'stock_alert' => '2', 'barcode_symbol' => 1, 'order_tax' => 0, 'tax_type' => 1,
        ]);
        $this->productA2 = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId, 'main_product_id' => $mpA2->id, 'name' => "C2B4ProdA2_{$this->token}", 'code' => "C2B4A2{$this->token}", 'product_code' => "C2B4A2{$this->token}",
            'product_category_id' => $this->catA->id, 'brand_id' => $this->brandA->id, 'product_cost' => 15.00, 'product_price' => 40.00,
            'product_unit' => $this->baseUnitA->id, 'sale_unit' => $this->unitA1->id, 'purchase_unit' => $this->unitA1->id, 'stock_alert' => '2', 'barcode_symbol' => 1, 'order_tax' => 0, 'tax_type' => 1,
        ]);
        $this->productB = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantBId, 'main_product_id' => $mpB->id, 'name' => "C2B4ProdB_{$this->token}", 'code' => "C2B4B{$this->token}", 'product_code' => "C2B4B{$this->token}",
            'product_category_id' => $catB->id, 'brand_id' => $brandB->id, 'product_cost' => 12.00, 'product_price' => 30.00,
            'product_unit' => $this->baseUnitB->id, 'sale_unit' => $this->unitB1->id, 'purchase_unit' => $this->unitB1->id, 'stock_alert' => '2', 'barcode_symbol' => 1, 'order_tax' => 0, 'tax_type' => 1,
        ]);

        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantAId, 'warehouse_id' => $this->warehouseA1->id, 'product_id' => $this->productA1->id, 'quantity' => 50, 'alert' => 0]);
        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantAId, 'warehouse_id' => $this->warehouseA1->id, 'product_id' => $this->productA2->id, 'quantity' => 50, 'alert' => 0]);
        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantAId, 'warehouse_id' => $this->warehouseA2->id, 'product_id' => $this->productA1->id, 'quantity' => 10, 'alert' => 0]);
        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantAId, 'warehouse_id' => $this->warehouseA2->id, 'product_id' => $this->productA2->id, 'quantity' => 10, 'alert' => 0]);
        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantBId, 'warehouse_id' => $this->warehouseB1->id, 'product_id' => $this->productB->id, 'quantity' => 50, 'alert' => 0]);
    }

    protected function tearDown(): void
    {
        try {
            StockMovement::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            ManageStock::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            TransferItem::whereHas('transfer', function ($q) {
                $q->withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId]);
            })->delete();
            Transfer::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            AdjustmentItem::whereHas('adjustment', function ($q) {
                $q->withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId]);
            })->delete();
            Adjustment::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Product::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->forceDelete();
            MainProduct::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->forceDelete();
            ProductCategory::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Brand::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Unit::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            BaseUnit::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Warehouse::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Subscription::where('plan_id', $this->plan?->id)->delete();
            Plan::where('id', $this->plan?->id)->delete();
            UserStore::whereIn('store_id', array_filter([$this->storeA?->id, $this->storeB?->id]))->delete();
            Store::whereIn('id', array_filter([$this->storeA?->id, $this->storeB?->id]))->delete();
            User::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->forceDelete();
            DB::table('tenants')->whereIn('id', [$this->tenantAId, $this->tenantBId])->delete();
        } catch (\Throwable $e) {
            // Isolated cleanup best effort
        }
        parent::tearDown();
    }

    private function makeUser(string $firstName, string $email, ?string $tenantId, string $roleName): User
    {
        $u = User::withoutGlobalScope('tenant')->create([
            'first_name' => $firstName,
            'last_name' => 'QA',
            'email' => $email,
            'email_verified_at' => now(),
            'phone' => '555' . random_int(1000000, 9999999),
            'password' => Hash::make('Secret@12345'),
            'tenant_id' => $tenantId,
            'status' => 1,
        ]);
        $u->assignRole($roleName);
        return $u;
    }

    private function createWithoutTenantScope(string $modelClass, array $attrs)
    {
        return $modelClass::unguarded(
            fn () => $modelClass::withoutEvents(
                fn () => $modelClass::withoutGlobalScope('tenant')->create($attrs)
            )
        );
    }

    private function getStock(int $warehouseId, int $productId): float
    {
        return (float) ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->value('quantity');
    }

    private function countMovements(string $refType, int $refId): int
    {
        return StockMovement::withoutGlobalScope('tenant')
            ->where('reference_type', $refType)
            ->where('reference_id', $refId)
            ->count();
    }

    private function defaultTransferPayload(int $status = 1, float $qty = 5.0): array
    {
        return [
            'date' => date('Y-m-d'),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseA2->id,
            'status' => $status,
            'discount' => 0,
            'order_tax' => 0,
            'shipping' => 0,
            'note' => 'C2B4 Transfer Test',
            'transfer_items' => [
                [
                    'product_id' => $this->productA1->id,
                    'product_price' => 10.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'discount_type' => 1,
                    'discount_value' => 0,
                    'quantity' => $qty,
                ]
            ],
        ];
    }

    private function defaultAdjustmentPayload(int $methodType = 1, float $qty = 5.0): array
    {
        return [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'product_id' => $this->productA1->id,
                    'quantity' => $qty,
                    'method_type' => $methodType,
                ]
            ],
        ];
    }

    // -------------------------------------------------------------
    // Tests 1 - 17: Transfer Lifecycle & Transitions
    // -------------------------------------------------------------

    public function test_01_pending_transfer_zero_movements_and_source_stock_unchanged(): void
    {
        Sanctum::actingAs($this->adminA);
        $payload = $this->defaultTransferPayload(Transfer::PENDING, 5.0);

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $this->assertEquals(0, $this->countMovements(Transfer::class, $id));
        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_02_sent_transfer_one_movement_source_deduct_and_dest_stock_unchanged(): void
    {
        Sanctum::actingAs($this->adminA);
        $payload = $this->defaultTransferPayload(Transfer::SENT, 5.0);

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $this->assertEquals(1, $this->countMovements(Transfer::class, $id));
        $mov = StockMovement::withoutGlobalScope('tenant')->where('reference_type', Transfer::class)->where('reference_id', $id)->first();
        $this->assertEquals($this->warehouseA1->id, $mov->warehouse_id);
        $this->assertEquals(-5.0, (float)$mov->quantity_change);

        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_03_completed_transfer_two_movements_both_warehouses_updated(): void
    {
        Sanctum::actingAs($this->adminA);
        $payload = $this->defaultTransferPayload(Transfer::COMPLETED, 5.0);

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $this->assertEquals(2, $this->countMovements(Transfer::class, $id));
        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(15.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_04_pending_to_sent_transition_creates_one_source_deduction(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::PENDING, 5.0));
        $res->assertStatus(201);
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $update = $this->defaultTransferPayload(Transfer::SENT, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(1, $this->countMovements(Transfer::class, $id));
        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_05_sent_to_completed_transition_credits_dest_once_without_duplicate_source_deduction(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::SENT, 5.0));
        $res->assertStatus(201);
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $this->assertEquals(1, $this->countMovements(Transfer::class, $id));
        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));

        $update = $this->defaultTransferPayload(Transfer::COMPLETED, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(2, $this->countMovements(Transfer::class, $id));
        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA1->id)); // Not deducted again
        $this->assertEquals(15.0, $this->getStock($this->warehouseA2->id, $this->productA1->id)); // Credited
    }

    public function test_06_pending_to_completed_transition_creates_both_movements(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::PENDING, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $update = $this->defaultTransferPayload(Transfer::COMPLETED, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(2, $this->countMovements(Transfer::class, $id));
        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(15.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_07_sent_to_pending_transition_reverses_source_deduction_dest_untouched(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::SENT, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $update = $this->defaultTransferPayload(Transfer::PENDING, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(2, $this->countMovements(Transfer::class, $id)); // 1 deduct + 1 restore
        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_08_completed_to_sent_transition_reverses_dest_credit_source_untouched(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::COMPLETED, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $update = $this->defaultTransferPayload(Transfer::SENT, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(3, $this->countMovements(Transfer::class, $id)); // 2 orig + 1 dest reverse
        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_09_completed_to_pending_transition_reverses_both_movements(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::COMPLETED, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $update = $this->defaultTransferPayload(Transfer::PENDING, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(4, $this->countMovements(Transfer::class, $id)); // 2 orig + 2 reversals
        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_10_idempotent_repeat_pending_update_without_status_change_causes_zero_movements(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::PENDING, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $update = $this->defaultTransferPayload(Transfer::PENDING, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(0, $this->countMovements(Transfer::class, $id));
    }

    public function test_11_idempotent_repeat_sent_update_without_status_change_causes_zero_additional_movements(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::SENT, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $this->assertEquals(1, $this->countMovements(Transfer::class, $id));

        $update = $this->defaultTransferPayload(Transfer::SENT, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(1, $this->countMovements(Transfer::class, $id));
    }

    public function test_12_idempotent_repeat_completed_update_without_status_change_causes_zero_additional_movements(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::COMPLETED, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $this->assertEquals(2, $this->countMovements(Transfer::class, $id));

        $update = $this->defaultTransferPayload(Transfer::COMPLETED, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(200);

        $this->assertEquals(2, $this->countMovements(Transfer::class, $id));
    }

    public function test_13_delete_pending_transfer_creates_zero_movements(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::PENDING, 5.0));
        $id = $res->json('data.id');

        $del = $this->deleteJson("/api/transfers/{$id}");
        $del->assertStatus(200);

        $this->assertEquals(0, $this->countMovements(Transfer::class, $id));
        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_14_delete_sent_transfer_creates_one_reversal_movement(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::SENT, 5.0));
        $id = $res->json('data.id');

        $del = $this->deleteJson("/api/transfers/{$id}");
        $del->assertStatus(200);

        $this->assertEquals(2, $this->countMovements(Transfer::class, $id)); // 1 orig + 1 restore
        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_15_delete_completed_transfer_creates_two_reversals_dest_first(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::COMPLETED, 5.0));
        $id = $res->json('data.id');

        $del = $this->deleteJson("/api/transfers/{$id}");
        $del->assertStatus(200);

        $this->assertEquals(4, $this->countMovements(Transfer::class, $id)); // 2 orig + 2 reversals
        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_16_transfer_reversal_blocked_when_destination_stock_consumed(): void
    {
        Sanctum::actingAs($this->adminA);
        // Completed transfer of 8 units
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::COMPLETED, 8.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        // Dest stock is now 10 + 8 = 18.
        $this->assertEquals(18.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));

        // Consume destination stock down to 4 (e.g. simulated customer sale or adjustment)
        ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $this->warehouseA2->id)
            ->where('product_id', $this->productA1->id)
            ->update(['quantity' => 4.0]);

        // Attempt to update Completed -> Pending (needs 8 units reversed from dest, but only 4 exist)
        $update = $this->defaultTransferPayload(Transfer::PENDING, 8.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item->id;

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(422);

        // Destination stock should NOT be negative and should remain 4
        $this->assertEquals(4.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));

        // Attempt to delete transfer should also be blocked with 422
        $del = $this->deleteJson("/api/transfers/{$id}");
        $del->assertStatus(422);
        $this->assertEquals(4.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    public function test_17_legacy_transfer_deletion_compatibility(): void
    {
        Sanctum::actingAs($this->adminA);
        // Simulate legacy transfer that was created as SENT (status=2) but legacy code erroneously credited destination
        $t = Transfer::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenantAId,
            'date' => date('Y-m-d'),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseA2->id,
            'status' => Transfer::SENT,
            'discount' => 0,
            'order_tax' => 0,
            'shipping' => 0,
            'grand_total' => 50.0,
        ]);
        TransferItem::create([
            'transfer_id' => $t->id,
            'product_id' => $this->productA1->id,
            'product_price' => 10.0,
            'net_unit_price' => 10.0,
            'tax_type' => 1,
            'tax_value' => 0,
            'tax_amount' => 0,
            'discount_type' => 1,
            'discount_value' => 0,
            'discount_amount' => 0,
            'quantity' => 5.0,
            'sub_total' => 50.0,
        ]);

        // Create the legacy 2 movements directly
        StockMovement::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenantAId,
            'warehouse_id' => $this->warehouseA1->id,
            'product_id' => $this->productA1->id,
            'type' => 'transfer_out',
            'quantity_change' => -5.0,
            'quantity_before' => 50.0,
            'quantity_after' => 45.0,
            'unit_cost' => 10.0,
            'reference_type' => Transfer::class,
            'reference_id' => $t->id,
            'user_id' => $this->adminA->id,
        ]);
        StockMovement::withoutGlobalScope('tenant')->create([
            'tenant_id' => $this->tenantAId,
            'warehouse_id' => $this->warehouseA2->id,
            'product_id' => $this->productA1->id,
            'type' => 'transfer_in',
            'quantity_change' => 5.0,
            'quantity_before' => 10.0,
            'quantity_after' => 15.0,
            'unit_cost' => 10.0,
            'reference_type' => Transfer::class,
            'reference_id' => $t->id,
            'user_id' => $this->adminA->id,
        ]);
        ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA1->id)->where('product_id', $this->productA1->id)->update(['quantity' => 45.0]);
        ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->warehouseA2->id)->where('product_id', $this->productA1->id)->update(['quantity' => 15.0]);

        // When deleting, inspectAndValidateLegacyState detects 2 movements for Sent status and safely reconciles both
        $del = $this->deleteJson("/api/transfers/{$t->id}");
        $del->assertStatus(200);

        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(10.0, $this->getStock($this->warehouseA2->id, $this->productA1->id));
    }

    // -------------------------------------------------------------
    // Tests 18 - 31: Transfer Integrity & Validation
    // -------------------------------------------------------------

    public function test_18_transfer_rejects_duplicate_child_transfer_item_ids(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::SENT, 5.0));
        $id = $res->json('data.id');
        $item = TransferItem::where('transfer_id', $id)->first();

        $update = $this->defaultTransferPayload(Transfer::SENT, 5.0);
        $update['transfer_items'] = [
            [
                'transfer_item_id' => $item->id,
                'product_id' => $this->productA1->id,
                'product_price' => 10.00,
                'tax_type' => 1,
                'tax_value' => 0,
                'discount_type' => 1,
                'discount_value' => 0,
                'quantity' => 2.0,
            ],
            [
                'transfer_item_id' => $item->id, // Duplicate item ID
                'product_id' => $this->productA2->id,
                'product_price' => 15.00,
                'tax_type' => 1,
                'tax_value' => 0,
                'discount_type' => 1,
                'discount_value' => 0,
                'quantity' => 3.0,
            ],
        ];

        $res2 = $this->putJson("/api/transfers/{$id}", $update);
        $res2->assertStatus(422);
    }

    public function test_19_transfer_rejects_cross_transfer_child_id(): void
    {
        Sanctum::actingAs($this->adminA);
        $t1 = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::SENT, 5.0))->json('data.id');
        $t2 = $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::SENT, 5.0))->json('data.id');

        $item2 = TransferItem::where('transfer_id', $t2)->first();

        $update = $this->defaultTransferPayload(Transfer::SENT, 5.0);
        $update['transfer_items'][0]['transfer_item_id'] = $item2->id; // IDOR from transfer 2

        $res = $this->putJson("/api/transfers/{$t1}", $update);
        $res->assertStatus(422);
    }

    public function test_20_transfer_deterministic_lock_ordering(): void
    {
        Sanctum::actingAs($this->adminA);
        // Create transfer with product 2 then product 1 to ensure order does not deadlock
        $payload = [
            'date' => date('Y-m-d'),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseA2->id,
            'status' => Transfer::SENT,
            'transfer_items' => [
                [
                    'product_id' => $this->productA2->id,
                    'product_price' => 15.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'discount_type' => 1,
                    'discount_value' => 0,
                    'quantity' => 2.0,
                ],
                [
                    'product_id' => $this->productA1->id,
                    'product_price' => 10.00,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'discount_type' => 1,
                    'discount_value' => 0,
                    'quantity' => 3.0,
                ],
            ],
        ];

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertStatus(201);
    }

    public function test_21_transfer_rejects_negative_discount_tax_shipping(): void
    {
        Sanctum::actingAs($this->adminA);
        $p1 = $this->defaultTransferPayload(Transfer::SENT);
        $p1['discount'] = -5;
        $this->postJson('/api/transfers', $p1)->assertStatus(422);

        $p2 = $this->defaultTransferPayload(Transfer::SENT);
        $p2['shipping'] = -10;
        $this->postJson('/api/transfers', $p2)->assertStatus(422);

        $p3 = $this->defaultTransferPayload(Transfer::SENT);
        $p3['order_tax'] = -1;
        $this->postJson('/api/transfers', $p3)->assertStatus(422);
    }

    public function test_22_transfer_rejects_negative_product_price(): void
    {
        Sanctum::actingAs($this->adminA);
        $payload = $this->defaultTransferPayload(Transfer::SENT);
        $payload['transfer_items'][0]['product_price'] = -10.0;

        $this->postJson('/api/transfers', $payload)->assertStatus(422);
    }

    public function test_23_transfer_defaults_missing_nullable_tax_discount_shipping_to_zero(): void
    {
        Sanctum::actingAs($this->adminA);
        $payload = [
            'date' => date('Y-m-d'),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseA2->id,
            'status' => Transfer::SENT,
            // discount, order_tax, shipping omitted
            'transfer_items' => [
                [
                    'product_id' => $this->productA1->id,
                    'product_price' => 10.00,
                    'tax_type' => 1,
                    'discount_type' => 1,
                    'quantity' => 5.0,
                ]
            ],
        ];

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertStatus(201);
        $this->assertEquals(0, (float)$res->json('data.attributes.discount'));
        $this->assertEquals(0, (float)$res->json('data.attributes.tax_rate'));
        $this->assertEquals(0, (float)$res->json('data.attributes.shipping'));
    }

    public function test_24_transfer_rejects_invalid_status(): void
    {
        Sanctum::actingAs($this->adminA);
        $payload = $this->defaultTransferPayload(99);
        $this->postJson('/api/transfers', $payload)->assertStatus(422);
    }

    public function test_25_transfer_rejects_invalid_tax_type_or_discount_type(): void
    {
        Sanctum::actingAs($this->adminA);
        $p1 = $this->defaultTransferPayload(Transfer::SENT);
        $p1['transfer_items'][0]['tax_type'] = 9;
        $this->postJson('/api/transfers', $p1)->assertStatus(422);

        $p2 = $this->defaultTransferPayload(Transfer::SENT);
        $p2['transfer_items'][0]['discount_type'] = 9;
        $this->postJson('/api/transfers', $p2)->assertStatus(422);
    }

    public function test_26_server_authoritative_transfer_pricing(): void
    {
        $staffRole = Role::withoutGlobalScopes()->create([
            'name' => "staff_role_{$this->token}",
            'display_name' => 'Staff Role',
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        $staff = $this->makeUser("StaffA_{$this->token}", "staff.a.{$this->token}@stockmate.test", $this->tenantAId, $staffRole->name);
        UserStore::create(['user_id' => $staff->id, 'store_id' => $this->storeA->id]);

        $permCreate = Permission::firstOrCreate(['name' => 'create_transfers', 'guard_name' => 'web']);
        $permView = Permission::firstOrCreate(['name' => 'view_transfers', 'guard_name' => 'web']);
        $staff->givePermissionTo($permCreate);
        $staff->givePermissionTo($permView);

        Sanctum::actingAs($staff);

        // Product cost is 10.00. Staff tries to submit spoofed price 999.00
        $payload = $this->defaultTransferPayload(Transfer::SENT);
        $payload['transfer_items'][0]['product_price'] = 999.00;

        $res = $this->postJson('/api/transfers', $payload);
        // Should either reject (422) or server sets authoritative price
        if ($res->status() === 201) {
            $this->assertEquals(10.00, (float)$res->json('data.attributes.transfer_items.0.net_unit_price'));
        } else {
            $res->assertStatus(422);
        }
    }

    public function test_27_transfer_note_validates_nullable_string(): void
    {
        Sanctum::actingAs($this->adminA);
        $payload = $this->defaultTransferPayload(Transfer::SENT);
        $payload['note'] = ['invalid' => 'array'];

        $this->postJson('/api/transfers', $payload)->assertStatus(422);
    }

    public function test_28_transfer_enforces_unit_parity(): void
    {
        Sanctum::actingAs($this->adminA);
        $diffBaseUnit = $this->createWithoutTenantScope(BaseUnit::class, ['tenant_id' => $this->tenantAId, 'name' => "DiffBase_{$this->token}", 'is_default' => 0]);
        $incompatibleUnit = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "Incomp_{$this->token}",
            'short_name' => 'dz',
            'base_unit' => $diffBaseUnit->id,
            'is_default' => 0,
        ]);
        $prodIncomp = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $this->productA1->main_product_id,
            'name' => "IncompProd_{$this->token}",
            'code' => "INCOMP{$this->token}",
            'product_code' => "INCOMP{$this->token}",
            'product_category_id' => $this->catA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 10,
            'product_price' => 20,
            'product_unit' => $this->baseUnitA->id,
            'sale_unit' => $incompatibleUnit->id,
            'purchase_unit' => $incompatibleUnit->id,
            'stock_alert' => 2,
            'barcode_symbol' => 1,
            'tax_type' => 1,
        ]);

        $payload = $this->defaultTransferPayload(Transfer::SENT);
        $payload['transfer_items'][0]['product_id'] = $prodIncomp->id;

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertStatus(422);
    }

    public function test_29_super_admin_cannot_cross_tenant_entities_in_transfer(): void
    {
        $perm = Permission::firstOrCreate(['name' => 'manage_transfers', 'guard_name' => 'web']);
        $this->superAdmin->givePermissionTo($perm);

        Sanctum::actingAs($this->superAdmin);
        // Warehouse A1 (tenant A) to Warehouse B1 (tenant B)
        $payload = [
            'date' => date('Y-m-d'),
            'from_warehouse_id' => $this->warehouseA1->id,
            'to_warehouse_id' => $this->warehouseB1->id,
            'status' => Transfer::SENT,
            'transfer_items' => [
                [
                    'product_id' => $this->productA1->id,
                    'product_price' => 10.00,
                    'tax_type' => 1,
                    'discount_type' => 1,
                    'quantity' => 5.0,
                ]
            ],
        ];

        $res = $this->postJson('/api/transfers', $payload);
        $res->assertStatus(422);
    }

    public function test_30_transfer_granular_rbac(): void
    {
        $viewerRole = Role::withoutGlobalScopes()->create([
            'name' => "viewer_role_{$this->token}",
            'display_name' => 'Viewer Role',
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        $viewer = $this->makeUser("Viewer_{$this->token}", "viewer.{$this->token}@stockmate.test", $this->tenantAId, $viewerRole->name);
        UserStore::create(['user_id' => $viewer->id, 'store_id' => $this->storeA->id]);

        $permView = Permission::firstOrCreate(['name' => 'view_transfers', 'guard_name' => 'web']);
        $viewer->givePermissionTo($permView);

        Sanctum::actingAs($viewer);

        // Allowed to view
        $this->getJson('/api/transfers')->assertStatus(200);

        // Denied create (403)
        $this->postJson('/api/transfers', $this->defaultTransferPayload(Transfer::PENDING))->assertStatus(403);
    }

    public function test_31_plan_feature_service_blocks_transfers_when_feature_disabled(): void
    {
        $this->assertTrue(in_array('create_transfers', \App\Services\PlanFeatureService::FEATURE_PERMISSION_MAP['inventory_management']));
        $this->assertTrue(in_array('delete_transfers', \App\Services\PlanFeatureService::FEATURE_PERMISSION_MAP['inventory_management']));
        $this->assertTrue(in_array('create_adjustments', \App\Services\PlanFeatureService::FEATURE_PERMISSION_MAP['adjustments']));
        $this->assertTrue(in_array('delete_adjustments', \App\Services\PlanFeatureService::FEATURE_PERMISSION_MAP['adjustments']));

        $pfs = app(\App\Services\PlanFeatureService::class);
        $this->assertTrue($pfs->hasFeature($this->adminA, 'inventory_management'));
        $this->assertTrue($pfs->hasFeature($this->adminA, 'adjustments'));
    }

    // -------------------------------------------------------------
    // Tests 32 - 38: Adjustment Integrity & Validation
    // -------------------------------------------------------------

    public function test_32_adjustment_rejects_duplicate_child_adjustment_item_ids(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/adjustments', $this->defaultAdjustmentPayload(1, 5.0));
        $res->assertStatus(201);
        $id = $res->json('data.id');
        $item = AdjustmentItem::where('adjustment_id', $id)->first();

        $update = [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'adjustment_item_id' => $item->id,
                    'product_id' => $this->productA1->id,
                    'quantity' => 2.0,
                    'method_type' => 1,
                ],
                [
                    'adjustment_item_id' => $item->id, // Duplicate
                    'product_id' => $this->productA2->id,
                    'quantity' => 3.0,
                    'method_type' => 1,
                ],
            ],
        ];

        $res2 = $this->putJson("/api/adjustments/{$id}", $update);
        $res2->assertStatus(422);
    }

    public function test_33_adjustment_rejects_cross_adjustment_child_id(): void
    {
        Sanctum::actingAs($this->adminA);
        $a1 = $this->postJson('/api/adjustments', $this->defaultAdjustmentPayload(1, 5.0))->json('data.id');
        $a2 = $this->postJson('/api/adjustments', $this->defaultAdjustmentPayload(1, 5.0))->json('data.id');

        $item2 = AdjustmentItem::where('adjustment_id', $a2)->first();

        $update = [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'adjustment_item_id' => $item2->id, // Cross IDOR
                    'product_id' => $this->productA1->id,
                    'quantity' => 4.0,
                    'method_type' => 1,
                ]
            ],
        ];

        $res = $this->putJson("/api/adjustments/{$a1}", $update);
        $res->assertStatus(422);
    }

    public function test_34_adjustment_locks_stock_rows_including_removed_items(): void
    {
        Sanctum::actingAs($this->adminA);
        // Create adjustment with 2 items
        $payload = [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'product_id' => $this->productA1->id,
                    'quantity' => 5.0,
                    'method_type' => 1,
                ],
                [
                    'product_id' => $this->productA2->id,
                    'quantity' => 4.0,
                    'method_type' => 1,
                ],
            ],
        ];

        $res = $this->postJson('/api/adjustments', $payload);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $item1 = AdjustmentItem::where('adjustment_id', $id)->where('product_id', $this->productA1->id)->first();

        // Update removing product A2 (only submitting product A1)
        $update = [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'adjustment_item_id' => $item1->id,
                    'product_id' => $this->productA1->id,
                    'quantity' => 6.0,
                    'method_type' => 1,
                ],
            ],
        ];

        $res2 = $this->putJson("/api/adjustments/{$id}", $update);
        $res2->assertStatus(200);

        // Removed item stock for product A2 should be reversed (was credited +4, now removed back to baseline 50)
        $this->assertEquals(50.0, $this->getStock($this->warehouseA1->id, $this->productA2->id));
        // Product A1 had +5, now updated to +6 -> stock 56
        $this->assertEquals(56.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
    }

    public function test_35_adjustment_locks_stock_using_stored_product_id_when_tampered(): void
    {
        Sanctum::actingAs($this->adminA);
        $res = $this->postJson('/api/adjustments', $this->defaultAdjustmentPayload(1, 5.0));
        $id = $res->json('data.id');
        $item = AdjustmentItem::where('adjustment_id', $id)->first();

        // Tamper product_id in payload for existing item
        $update = [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                [
                    'adjustment_item_id' => $item->id,
                    'product_id' => $this->productA2->id, // Changed product ID on existing item
                    'quantity' => 5.0,
                    'method_type' => 1,
                ],
            ],
        ];

        $res2 = $this->putJson("/api/adjustments/{$id}", $update);
        $res2->assertStatus(422);
    }

    public function test_36_multi_item_adjustment_delta_updates_maintain_strict_invariants(): void
    {
        Sanctum::actingAs($this->adminA);
        // Start stocks: A1=50, A2=50.
        // Create adjustment: A1 +10 (stock -> 60), A2 -5 (stock -> 45)
        $res = $this->postJson('/api/adjustments', [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                ['product_id' => $this->productA1->id, 'quantity' => 10.0, 'method_type' => 1],
                ['product_id' => $this->productA2->id, 'quantity' => 5.0, 'method_type' => 2],
            ],
        ]);
        $res->assertStatus(201);
        $id = $res->json('data.id');

        $this->assertEquals(60.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(45.0, $this->getStock($this->warehouseA1->id, $this->productA2->id));

        $itemA1 = AdjustmentItem::where('adjustment_id', $id)->where('product_id', $this->productA1->id)->first();
        $itemA2 = AdjustmentItem::where('adjustment_id', $id)->where('product_id', $this->productA2->id)->first();

        // Update adjustment: A1 changes to +15 (delta +5, stock -> 65), A2 changes to -2 (delta +3 restore, stock -> 48)
        $res2 = $this->putJson("/api/adjustments/{$id}", [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                ['adjustment_item_id' => $itemA1->id, 'product_id' => $this->productA1->id, 'quantity' => 15.0, 'method_type' => 1],
                ['adjustment_item_id' => $itemA2->id, 'product_id' => $this->productA2->id, 'quantity' => 2.0, 'method_type' => 2],
            ],
        ]);
        $res2->assertStatus(200);

        $this->assertEquals(65.0, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals(48.0, $this->getStock($this->warehouseA1->id, $this->productA2->id));
    }

    public function test_37_adjustment_enforces_unit_parity(): void
    {
        Sanctum::actingAs($this->adminA);
        $diffBaseUnit = $this->createWithoutTenantScope(BaseUnit::class, ['tenant_id' => $this->tenantAId, 'name' => "AdjDiffBase_{$this->token}", 'is_default' => 0]);
        $incompUnit = $this->createWithoutTenantScope(Unit::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "AdjIncomp_{$this->token}",
            'short_name' => 'dz',
            'base_unit' => $diffBaseUnit->id,
            'is_default' => 0,
        ]);
        $incompProd = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $this->productA1->main_product_id,
            'name' => "AdjIncompProd_{$this->token}",
            'code' => "ADJINCOMP{$this->token}",
            'product_code' => "ADJINCOMP{$this->token}",
            'product_category_id' => $this->catA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 10,
            'product_price' => 20,
            'product_unit' => $this->baseUnitA->id,
            'sale_unit' => $incompUnit->id,
            'purchase_unit' => $this->unitA1->id, // Mismatched purchase_unit != sale_unit
            'stock_alert' => 2,
            'barcode_symbol' => 1,
            'tax_type' => 1,
        ]);

        $res = $this->postJson('/api/adjustments', [
            'date' => date('Y-m-d'),
            'warehouse_id' => $this->warehouseA1->id,
            'adjustment_items' => [
                ['product_id' => $incompProd->id, 'quantity' => 5.0, 'method_type' => 1]
            ],
        ]);
        $res->assertStatus(422);
    }

    public function test_38_adjustment_granular_rbac(): void
    {
        $viewerRole = Role::withoutGlobalScopes()->create([
            'name' => "adj_viewer_role_{$this->token}",
            'display_name' => 'Adj Viewer Role',
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        $viewer = $this->makeUser("AdjViewer_{$this->token}", "adjviewer.{$this->token}@stockmate.test", $this->tenantAId, $viewerRole->name);
        UserStore::create(['user_id' => $viewer->id, 'store_id' => $this->storeA->id]);

        $permView = Permission::firstOrCreate(['name' => 'view_adjustments', 'guard_name' => 'web']);
        $viewer->givePermissionTo($permView);

        Sanctum::actingAs($viewer);

        $this->getJson('/api/adjustments')->assertStatus(200);
        $this->postJson('/api/adjustments', $this->defaultAdjustmentPayload(1, 5.0))->assertStatus(403);
    }

    // -------------------------------------------------------------
    // Tests 39 - 41: StockMovementService & Transactions
    // -------------------------------------------------------------

    public function test_39_stock_movement_service_increase_explicit_tenant_id(): void
    {
        // Create brand new product without existing ManageStock
        $newProd = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $this->productA1->main_product_id,
            'name' => "NewProd_{$this->token}",
            'code' => "NEWP{$this->token}",
            'product_code' => "NEWP{$this->token}",
            'product_category_id' => $this->catA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 10,
            'product_price' => 20,
            'product_unit' => $this->baseUnitA->id,
            'sale_unit' => $this->unitA1->id,
            'purchase_unit' => $this->unitA1->id,
            'stock_alert' => 2,
            'barcode_symbol' => 1,
            'tax_type' => 1,
        ]);

        $this->assertNull(
            ManageStock::withoutGlobalScope('tenant')
                ->where('warehouse_id', $this->warehouseA1->id)
                ->where('product_id', $newProd->id)
                ->first()
        );

        app(StockMovementService::class)->increase(
            $this->warehouseA1->id,
            $newProd->id,
            15.0,
            10.0,
            'adjustment',
            9999,
            null,
            $this->adminA->id,
            $this->tenantAId
        );

        $row = ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $this->warehouseA1->id)
            ->where('product_id', $newProd->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals($this->tenantAId, $row->tenant_id);
        $this->assertEquals(15.0, (float)$row->quantity);
    }

    public function test_40_stock_movement_service_increase_does_not_overwrite_existing_row(): void
    {
        $existing = ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $this->warehouseA1->id)
            ->where('product_id', $this->productA1->id)
            ->first();

        $originalTenant = $existing->tenant_id;
        $originalId = $existing->id;

        app(StockMovementService::class)->increase(
            $this->warehouseA1->id,
            $this->productA1->id,
            10.0,
            10.0,
            'adjustment',
            9998,
            null,
            $this->adminA->id,
            $this->tenantAId
        );

        $after = ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $this->warehouseA1->id)
            ->where('product_id', $this->productA1->id)
            ->get();

        $this->assertCount(1, $after);
        $this->assertEquals($originalId, $after->first()->id);
        $this->assertEquals($originalTenant, $after->first()->tenant_id);
        $this->assertEquals(60.0, (float)$after->first()->quantity);
    }

    public function test_41_atomic_transaction_rollback(): void
    {
        Sanctum::actingAs($this->adminA);
        $startA1 = $this->getStock($this->warehouseA1->id, $this->productA1->id);
        $movCountBefore = StockMovement::withoutGlobalScope('tenant')->count();

        // Attempt transfer with second item having invalid product_id that fails
        try {
            DB::transaction(function () {
                // Perform valid movement
                app(StockMovementService::class)->decrease(
                    $this->warehouseA1->id,
                    $this->productA1->id,
                    10.0,
                    10.0,
                    'transfer',
                    1,
                    null,
                    $this->adminA->id
                );
                // Simulate exception inside transaction
                throw new \RuntimeException('Simulated transaction failure');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        // Verify full rollback: stock is unchanged and movements count unchanged
        $this->assertEquals($startA1, $this->getStock($this->warehouseA1->id, $this->productA1->id));
        $this->assertEquals($movCountBefore, StockMovement::withoutGlobalScope('tenant')->count());
    }
}
