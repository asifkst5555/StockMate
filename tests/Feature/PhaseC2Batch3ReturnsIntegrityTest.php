<?php

namespace Tests\Feature;

use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MailTemplate;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhaseC2Batch3ReturnsIntegrityTest extends TestCase
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
    private ?Customer $customerA2 = null;
    private ?Customer $customerB = null;
    private ?Supplier $supplierA = null;
    private ?Supplier $supplierA2 = null;
    private ?Supplier $supplierB = null;
    private ?BaseUnit $baseUnitA = null;
    private ?BaseUnit $baseUnitB = null;
    private ?Unit $unitA1 = null;
    private ?Unit $unitA2 = null;
    private ?Unit $unitB1 = null;
    private ?Product $productA1 = null;
    private ?Product $productA2 = null;
    private ?Product $productB = null;
    private ?PaymentMethod $pmA = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c2b3_tA_' . $this->token;
        $this->tenantBId = 'c2b3_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c2b3_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c2b3_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C2B3AdminA_{$this->token}", "c2b3.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("C2B3AdminB_{$this->token}", "c2b3.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);

        $this->storeA = Store::create(['name' => "C2B3 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "C2B3 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "C2B3 Plan {$this->token}",
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
            'tenant_id' => $this->tenantAId, 'name' => "C2B3WH_A1_{$this->token}", 'phone' => '1111111111', 'country' => 'US', 'city' => 'NY', 'email' => "wha1.{$this->token}@test.local",
        ]);
        $this->warehouseA2 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B3WH_A2_{$this->token}", 'phone' => '1111111112', 'country' => 'US', 'city' => 'LA', 'email' => "wha2.{$this->token}@test.local",
        ]);
        $this->warehouseB1 = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantBId, 'name' => "C2B3WH_B1_{$this->token}", 'phone' => '2222222221', 'country' => 'US', 'city' => 'TX', 'email' => "whb1.{$this->token}@test.local",
        ]);

        $this->customerA = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B3CustA_{$this->token}", 'email' => "custa.{$this->token}@test.local", 'phone' => '3111111111', 'country' => 'US', 'city' => 'NY', 'address' => '1 Main',
        ]);
        $this->customerA2 = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B3CustA2_{$this->token}", 'email' => "custa2.{$this->token}@test.local", 'phone' => '3111111112', 'country' => 'US', 'city' => 'NY', 'address' => '2 Main',
        ]);
        $this->customerB = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantBId, 'name' => "C2B3CustB_{$this->token}", 'email' => "custb.{$this->token}@test.local", 'phone' => '3222222222', 'country' => 'US', 'city' => 'TX', 'address' => '3 Main',
        ]);

        $this->supplierA = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B3SupA_{$this->token}", 'email' => "supa.{$this->token}@test.local", 'phone' => '4111111111', 'country' => 'US', 'city' => 'NY', 'address' => '1 Sup',
        ]);
        $this->supplierA2 = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantAId, 'name' => "C2B3SupA2_{$this->token}", 'email' => "supa2.{$this->token}@test.local", 'phone' => '4111111112', 'country' => 'US', 'city' => 'NY', 'address' => '2 Sup',
        ]);
        $this->supplierB = $this->createWithoutTenantScope(Supplier::class, [
            'tenant_id' => $this->tenantBId, 'name' => "C2B3SupB_{$this->token}", 'email' => "supb.{$this->token}@test.local", 'phone' => '4222222222', 'country' => 'US', 'city' => 'TX', 'address' => '3 Sup',
        ]);

        $this->baseUnitA = $this->createWithoutTenantScope(BaseUnit::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B3PcA_{$this->token}", 'is_default' => 0]);
        $this->baseUnitB = $this->createWithoutTenantScope(BaseUnit::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B3PcB_{$this->token}", 'is_default' => 0]);

        $this->unitA1 = $this->createWithoutTenantScope(Unit::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B3UnitA1_{$this->token}", 'short_name' => 'pc', 'base_unit' => $this->baseUnitA->id, 'is_default' => 0]);
        $this->unitA2 = $this->createWithoutTenantScope(Unit::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B3UnitA2_{$this->token}", 'short_name' => 'bx', 'base_unit' => $this->baseUnitA->id, 'is_default' => 0]);
        $this->unitB1 = $this->createWithoutTenantScope(Unit::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B3UnitB1_{$this->token}", 'short_name' => 'pc', 'base_unit' => $this->baseUnitB->id, 'is_default' => 0]);

        $brandA = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B3BrandA_{$this->token}"]);
        $brandB = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B3BrandB_{$this->token}"]);
        $catA = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B3CatA_{$this->token}"]);
        $catB = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B3CatB_{$this->token}"]);

        $mpA1 = $this->createWithoutTenantScope(MainProduct::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B3ProdA1_{$this->token}", 'code' => "C2B3A1{$this->token}", 'product_unit' => $this->baseUnitA->id, 'product_type' => 1]);
        $mpA2 = $this->createWithoutTenantScope(MainProduct::class, ['tenant_id' => $this->tenantAId, 'name' => "C2B3ProdA2_{$this->token}", 'code' => "C2B3A2{$this->token}", 'product_unit' => $this->baseUnitA->id, 'product_type' => 1]);
        $mpB = $this->createWithoutTenantScope(MainProduct::class, ['tenant_id' => $this->tenantBId, 'name' => "C2B3ProdB_{$this->token}", 'code' => "C2B3B{$this->token}", 'product_unit' => $this->baseUnitB->id, 'product_type' => 1]);

        $this->productA1 = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId, 'main_product_id' => $mpA1->id, 'name' => "C2B3ProdA1_{$this->token}", 'code' => "C2B3A1{$this->token}", 'product_code' => "C2B3A1{$this->token}",
            'product_category_id' => $catA->id, 'brand_id' => $brandA->id, 'product_cost' => 10.00, 'product_price' => 25.00,
            'product_unit' => $this->baseUnitA->id, 'sale_unit' => $this->unitA1->id, 'purchase_unit' => $this->unitA1->id, 'stock_alert' => '2', 'barcode_symbol' => 1, 'order_tax' => 0, 'tax_type' => 1,
        ]);
        $this->productA2 = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId, 'main_product_id' => $mpA2->id, 'name' => "C2B3ProdA2_{$this->token}", 'code' => "C2B3A2{$this->token}", 'product_code' => "C2B3A2{$this->token}",
            'product_category_id' => $catA->id, 'brand_id' => $brandA->id, 'product_cost' => 15.00, 'product_price' => 40.00,
            'product_unit' => $this->baseUnitA->id, 'sale_unit' => $this->unitA1->id, 'purchase_unit' => $this->unitA1->id, 'stock_alert' => '2', 'barcode_symbol' => 1, 'order_tax' => 0, 'tax_type' => 1,
        ]);
        $this->productB = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantBId, 'main_product_id' => $mpB->id, 'name' => "C2B3ProdB_{$this->token}", 'code' => "C2B3B{$this->token}", 'product_code' => "C2B3B{$this->token}",
            'product_category_id' => $catB->id, 'brand_id' => $brandB->id, 'product_cost' => 12.00, 'product_price' => 30.00,
            'product_unit' => $this->baseUnitB->id, 'sale_unit' => $this->unitB1->id, 'purchase_unit' => $this->unitB1->id, 'stock_alert' => '2', 'barcode_symbol' => 1, 'order_tax' => 0, 'tax_type' => 1,
        ]);

        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantAId, 'warehouse_id' => $this->warehouseA1->id, 'product_id' => $this->productA1->id, 'quantity' => 50, 'alert' => 0]);
        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantAId, 'warehouse_id' => $this->warehouseA1->id, 'product_id' => $this->productA2->id, 'quantity' => 50, 'alert' => 0]);
        $this->createWithoutTenantScope(ManageStock::class, ['tenant_id' => $this->tenantBId, 'warehouse_id' => $this->warehouseB1->id, 'product_id' => $this->productB->id, 'quantity' => 50, 'alert' => 0]);

        $this->pmA = $this->createWithoutTenantScope(PaymentMethod::class, [
            'tenant_id' => $this->tenantAId,
            'name' => 'Cash',
            'status' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        try {
            StockMovement::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            ManageStock::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            SaleReturn::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            PurchaseReturn::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Sale::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Purchase::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Product::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            MainProduct::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Unit::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            BaseUnit::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Brand::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            ProductCategory::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            PaymentMethod::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Customer::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Supplier::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Warehouse::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
            Subscription::where('plan_id', $this->plan?->id)->delete();
            Plan::where('id', $this->plan?->id)->delete();
            UserStore::whereIn('store_id', array_filter([$this->storeA?->id, $this->storeB?->id]))->delete();
            Store::whereIn('id', array_filter([$this->storeA?->id, $this->storeB?->id]))->delete();
            User::withoutGlobalScope('tenant')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->forceDelete();
            DB::table('tenants')->whereIn('id', [$this->tenantAId, $this->tenantBId])->delete();
        } catch (\Throwable $e) {
            // Isolated DB cleanup is best-effort
        }
        parent::tearDown();
    }

    private function makeUser(string $firstName, string $email, string $tenantId, string $roleName): User
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

    private function stockQty(int $productId): float
    {
        return (float) ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $this->warehouseA1->id)
            ->where('product_id', $productId)
            ->value('quantity');
    }

    private function createSaleForA(array $items = null, float $orderDiscount = 0, float $orderTaxRate = 0): int
    {
        $items ??= [[
            'product_id' => $this->productA1->id,
            'product_price' => 25.00,
            'quantity' => 5,
            'sale_unit' => $this->unitA1->id,
        ]];

        $saleItemsPayload = [];
        $subSum = 0.0;
        foreach ($items as $it) {
            $price = (float) ($it['product_price'] ?? 25.00);
            $qty = (float) $it['quantity'];
            $dType = $it['discount_type'] ?? Sale::FIXED;
            $dVal = (float) ($it['discount_value'] ?? 0);
            $tType = $it['tax_type'] ?? Sale::EXCLUSIVE;
            $tVal = (float) ($it['tax_value'] ?? 0);
            $net = $price - ($dType === Sale::PERCENTAGE ? ($price * $dVal / 100) : $dVal);
            $lineSub = ($tType === Sale::EXCLUSIVE ? $net * (1 + $tVal / 100) : $net) * $qty;
            $subSum += $lineSub;

            $saleItemsPayload[] = [
                'product_id' => $it['product_id'],
                'product_price' => $price,
                'net_unit_price' => $net,
                'tax_type' => $tType,
                'tax_value' => $tVal,
                'tax_amount' => 0,
                'discount_type' => $dType,
                'discount_value' => $dVal,
                'discount_amount' => 0,
                'sale_unit' => $it['sale_unit'] ?? $this->unitA1->id,
                'quantity' => $qty,
                'sub_total' => $lineSub,
            ];
        }

        $afterDiscount = max(0, $subSum - $orderDiscount);
        $taxAmt = $afterDiscount * $orderTaxRate / 100;
        $grand = $afterDiscount + $taxAmt;

        $res = $this->postJson('/api/sales', [
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => $orderTaxRate,
            'tax_amount' => $taxAmt,
            'discount' => $orderDiscount,
            'shipping' => 0,
            'grand_total' => $grand,
            'paid_amount' => $grand,
            'payment_type' => $this->pmA->id,
            'payment_status' => Sale::PAID,
            'status' => Sale::COMPLETED,
            'sale_items' => $saleItemsPayload,
            'payment_details' => [[
                'amount' => $grand,
                'payment_type' => ['value' => $this->pmA->id],
                'date' => Carbon::today()->toDateString(),
            ]],
        ]);
        $this->assertEquals(201, $res->status(), 'createSaleForA failed: ' . $res->getContent());

        return (int) $res->json('data.id');
    }

    private function createPurchaseForA(int $status = Purchase::RECEIVED, array $items = null, float $orderDiscount = 0, float $orderTaxRate = 0, ?int $supplierId = null): int
    {
        $items ??= [[
            'product_id' => $this->productA1->id,
            'product_cost' => 10.00,
            'quantity' => 5,
            'purchase_unit' => $this->unitA1->id,
        ]];

        $pItemsPayload = [];
        $subSum = 0.0;
        foreach ($items as $it) {
            $cost = (float) ($it['product_cost'] ?? 10.00);
            $qty = (float) $it['quantity'];
            $dType = $it['discount_type'] ?? Purchase::FIXED;
            $dVal = (float) ($it['discount_value'] ?? 0);
            $tType = $it['tax_type'] ?? Purchase::EXCLUSIVE;
            $tVal = (float) ($it['tax_value'] ?? 0);
            $net = $cost - ($dType === Purchase::PERCENTAGE ? ($cost * $dVal / 100) : $dVal);
            $lineSub = ($tType === Purchase::EXCLUSIVE ? $net * (1 + $tVal / 100) : $net) * $qty;
            $subSum += $lineSub;

            $pItemsPayload[] = [
                'product_id' => $it['product_id'],
                'product_cost' => $cost,
                'net_unit_cost' => $net,
                'tax_type' => $tType,
                'tax_value' => $tVal,
                'tax_amount' => 0,
                'discount_type' => $dType,
                'discount_value' => $dVal,
                'discount_amount' => 0,
                'purchase_unit' => $it['purchase_unit'] ?? $this->unitA1->id,
                'quantity' => $qty,
                'sub_total' => $lineSub,
            ];
        }

        $afterDiscount = max(0, $subSum - $orderDiscount);
        $taxAmt = $afterDiscount * $orderTaxRate / 100;
        $grand = $afterDiscount + $taxAmt;

        $res = $this->postJson('/api/purchases', [
            'date' => Carbon::today()->toDateString(),
            'supplier_id' => $supplierId ?? $this->supplierA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => $orderTaxRate,
            'tax_amount' => $taxAmt,
            'discount' => $orderDiscount,
            'shipping' => 0,
            'grand_total' => $grand,
            'received_amount' => 0,
            'paid_amount' => 0,
            'payment_type' => $this->pmA->id,
            'payment_status' => Purchase::UNPAID,
            'status' => $status,
            'purchase_items' => $pItemsPayload,
        ]);
        $this->assertEquals(201, $res->status(), 'createSaleForA failed: ' . $res->getContent());

        return (int) $res->json('data.id');
    }

    private function saleReturnPayload(int $saleId, int $status = SaleReturn::RECEIVED, float $qty = 2, float $price = 25.00, array $overrides = []): array
    {
        return array_replace_recursive([
            'sale_id' => $saleId,
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'paid_amount' => 0,
            'payment_type' => $this->pmA->id,
            'status' => $status,
            'note' => 'Batch 3A Sale Return',
            'sale_return_items' => [[
                'product_id' => $this->productA1->id,
                'product_price' => $price,
                'tax_type' => SaleReturn::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => SaleReturn::FIXED,
                'discount_value' => 0,
                'sale_unit' => $this->unitA1->id,
                'quantity' => $qty,
            ]],
        ], $overrides);
    }

    private function purchaseReturnPayload(?int $purchaseId, int $status = PurchaseReturn::COMPLETED, float $qty = 2, float $cost = 10.00, array $overrides = []): array
    {
        return array_replace_recursive([
            'purchase_id' => $purchaseId,
            'date' => Carbon::today()->toDateString(),
            'supplier_id' => $this->supplierA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'received_amount' => 0,
            'paid_amount' => 0,
            'payment_type' => $this->pmA->id,
            'payment_status' => PurchaseReturn::UNPAID,
            'status' => $status,
            'notes' => 'Batch 3A Purchase Return',
            'purchase_return_items' => [[
                'product_id' => $this->productA1->id,
                'product_cost' => $cost,
                'tax_type' => PurchaseReturn::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => PurchaseReturn::FIXED,
                'discount_value' => 0,
                'purchase_unit' => $this->unitA1->id,
                'quantity' => $qty,
            ]],
        ], $overrides);
    }

    /** Tests 1-7 & 24: Pending return lifecycle, idempotent transitions, delete safety, quota reservation/release */
    public function test_pending_return_lifecycle_transitions_deletes_and_quota_reservation(): void
    {
        Sanctum::actingAs($this->adminA);

        $saleId = $this->createSaleForA([[
            'product_id' => $this->productA1->id, 'product_price' => 25.00, 'quantity' => 5, 'sale_unit' => $this->unitA1->id,
        ]]);
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));

        // 1 & 24: Pending Sale Return creates 0 stock movement, reserves quota
        $srRes = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::PENDING, 4));
        $srRes->assertStatus(201);
        $srId = (int) $srRes->json('data.id');
        $srItemId = (int) SaleReturnItem::where('sale_return_id', $srId)->value('id');
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => SaleReturn::class, 'reference_id' => $srId]);

        // Pending reservation blocks returning 2 more (4 + 2 = 6 > 5)
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2))->assertStatus(422);

        // 24: Reducing pending qty (4 -> 2) releases reserved quota
        $reducePending = $this->saleReturnPayload($saleId, SaleReturn::PENDING, 2);
        $reducePending['sale_return_items'][0]['sale_return_item_id'] = $srItemId;
        $this->putJson("/api/sales-return/{$srId}", $reducePending)->assertStatus(200);
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));

        // 3: PENDING -> RECEIVED applies stock ONCE (45 -> 47)
        $toReceived = $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2);
        $toReceived['sale_return_items'][0]['sale_return_item_id'] = $srItemId;
        $this->putJson("/api/sales-return/{$srId}", $toReceived)->assertStatus(200);
        $this->assertEquals(47.0, $this->stockQty($this->productA1->id));

        // 5: Repeat RECEIVED -> RECEIVED update with same qty does NOT duplicate movement
        $beforeMoves = StockMovement::withoutGlobalScope('tenant')->where('reference_type', SaleReturn::class)->where('reference_id', $srId)->count();
        $this->putJson("/api/sales-return/{$srId}", $toReceived)->assertStatus(200);
        $afterMoves = StockMovement::withoutGlobalScope('tenant')->where('reference_type', SaleReturn::class)->where('reference_id', $srId)->count();
        $this->assertEquals($beforeMoves, $afterMoves);
        $this->assertEquals(47.0, $this->stockQty($this->productA1->id));

        // 4: RECEIVED -> PENDING reverses stock ONCE (47 -> 45)
        $toPending = $this->saleReturnPayload($saleId, SaleReturn::PENDING, 2);
        $toPending['sale_return_items'][0]['sale_return_item_id'] = $srItemId;
        $this->putJson("/api/sales-return/{$srId}", $toPending)->assertStatus(200);
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => SaleReturn::class, 'reference_id' => $srId, 'type' => 'sale_return_status_reversal']);

        // 6 & 24: Deleting PENDING Sale Return does not change stock (stays 45) and releases all quota (5 remaining)
        $this->deleteJson("/api/sales-return/{$srId}")->assertStatus(200);
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));

        // 7: Deleting RECEIVED Sale Return reverses stock once (45 -> 50 -> 45)
        $sr2Id = (int) $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 5))->assertStatus(201)->json('data.id');
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));
        $this->deleteJson("/api/sales-return/{$sr2Id}")->assertStatus(200);
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => SaleReturn::class, 'reference_id' => $sr2Id, 'type' => 'sale_return_delete_reversal']);

        // --- Purchase Return Lifecycle (Tests 2, 3, 4, 5, 6, 7) ---
        $purchaseId = $this->createPurchaseForA(Purchase::RECEIVED);
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));

        // 2: Pending Purchase Return creates 0 stock movement, reserves quota
        $prRes = $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::PENDING, 4));
        $prRes->assertStatus(201);
        $prId = (int) $prRes->json('data.id');
        $prItemId = (int) PurchaseReturnItem::where('purchase_return_id', $prId)->value('id');
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));
        $this->assertDatabaseMissing('stock_movements', ['reference_type' => PurchaseReturn::class, 'reference_id' => $prId]);

        // Pending -> Completed applies deduction once (50 -> 46)
        $prToCompleted = $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 4);
        $prToCompleted['purchase_return_items'][0]['purchase_return_item_id'] = $prItemId;
        $this->putJson("/api/purchases-return/{$prId}", $prToCompleted)->assertStatus(200);
        $this->assertEquals(46.0, $this->stockQty($this->productA1->id));

        // Repeat COMPLETED update does not duplicate movements
        $prMovesBefore = StockMovement::withoutGlobalScope('tenant')->where('reference_type', PurchaseReturn::class)->where('reference_id', $prId)->count();
        $this->putJson("/api/purchases-return/{$prId}", $prToCompleted)->assertStatus(200);
        $this->assertEquals($prMovesBefore, StockMovement::withoutGlobalScope('tenant')->where('reference_type', PurchaseReturn::class)->where('reference_id', $prId)->count());

        // COMPLETED -> PENDING restores stock once (46 -> 50)
        $prToPending = $this->purchaseReturnPayload($purchaseId, PurchaseReturn::PENDING, 4);
        $prToPending['purchase_return_items'][0]['purchase_return_item_id'] = $prItemId;
        $this->putJson("/api/purchases-return/{$prId}", $prToPending)->assertStatus(200);
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => PurchaseReturn::class, 'reference_id' => $prId, 'type' => 'purchase_return_status_reversal']);

        // Deleting PENDING Purchase Return does not mutate stock
        $this->deleteJson("/api/purchases-return/{$prId}")->assertStatus(200);
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));

        // Deleting COMPLETED Purchase Return reverses stock once (50 -> 47 -> 50)
        $pr2Id = (int) $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 3))->assertStatus(201)->json('data.id');
        $this->assertEquals(47.0, $this->stockQty($this->productA1->id));
        $this->deleteJson("/api/purchases-return/{$pr2Id}")->assertStatus(200);
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));
        $this->assertDatabaseHas('stock_movements', ['reference_type' => PurchaseReturn::class, 'reference_id' => $pr2Id, 'type' => 'purchase_return_delete_reversal']);
    }

    /** Tests 8-9: Parent Sale/Purchase deletion blocked when returns exist; succeeds when no returns exist */
    public function test_parent_sale_and_purchase_delete_blocked_with_returns_and_allowed_without(): void
    {
        Sanctum::actingAs($this->adminA);

        $saleId = $this->createSaleForA();
        $srId = (int) $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2))->assertStatus(201)->json('data.id');
        $this->assertEquals(47.0, $this->stockQty($this->productA1->id));

        // Blocked while SaleReturn exists
        $this->deleteJson("/api/sales/{$saleId}")->assertStatus(422);
        $this->assertDatabaseHas('sales', ['id' => $saleId]);
        $this->assertDatabaseHas('sales_return', ['id' => $srId]);
        $this->assertEquals(47.0, $this->stockQty($this->productA1->id));

        // Delete return first -> then Sale delete succeeds cleanly (47 -> 45 -> 50)
        $this->deleteJson("/api/sales-return/{$srId}")->assertStatus(200);
        $this->deleteJson("/api/sales/{$saleId}")->assertStatus(200);
        $this->assertDatabaseMissing('sales', ['id' => $saleId]);
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));

        // Purchase with linked PurchaseReturn blocked -> then allowed after return deletion
        $purchaseId = $this->createPurchaseForA(Purchase::RECEIVED);
        $prId = (int) $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 2))->assertStatus(201)->json('data.id');

        $this->deleteJson("/api/purchases/{$purchaseId}")->assertStatus(422);
        $this->assertDatabaseHas('purchases', ['id' => $purchaseId]);
        $this->assertDatabaseHas('purchases_return', ['id' => $prId]);

        $this->deleteJson("/api/purchases-return/{$prId}")->assertStatus(200);
        $this->deleteJson("/api/purchases/{$purchaseId}")->assertStatus(200);
        $this->assertDatabaseMissing('purchases', ['id' => $purchaseId]);
        $this->assertEquals(50.0, $this->stockQty($this->productA1->id));
    }

    /** Tests 11-12: markAsPaid updates payment_status & paid_amount safely without touching inventory status, blocks cross-tenant */
    public function test_mark_as_paid_and_cross_tenant_isolation(): void
    {
        Sanctum::actingAs($this->adminA);
        $saleId = $this->createSaleForA();
        $srRes = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::PENDING, 2, 25.00));
        $srRes->assertStatus(201);
        $srId = (int) $srRes->json('data.id');

        // PENDING return stock unchanged (45)
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));

        // Mark as paid on PENDING SaleReturn: payment_status=PAID, paid_amount=50, inventory status STILL PENDING (2)!
        $this->getJson("/api/sales-return/paid/{$srId}")->assertStatus(200)->assertJson(['success' => true]);
        $updated = SaleReturn::withoutGlobalScope('tenant')->findOrFail($srId);
        $this->assertEquals(SaleReturn::PAID, (int) $updated->payment_status);
        $this->assertEquals(50.0, (float) $updated->paid_amount);
        $this->assertEquals(SaleReturn::PENDING, (int) $updated->status);
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));

        // Cross-tenant Admin B cannot mark Tenant A return as paid -> 404
        Sanctum::actingAs($this->adminB);
        $this->getJson("/api/sales-return/paid/{$srId}")->assertStatus(404);
    }

    /** Test 13: Notification failure after commit never rolls back Sale Return */
    public function test_notification_transport_failure_does_not_rollback_sale_return(): void
    {
        Sanctum::actingAs($this->adminA);
        $saleId = $this->createSaleForA();

        $tpl = $this->createWithoutTenantScope(MailTemplate::class, [
            'tenant_id' => $this->tenantAId,
            'template_name' => 'Sale Return Test',
            'content' => 'Return {sales_return_id} for {customer_name}',
            'type' => MailTemplate::MAIL_TYPE_SALE_RETURN,
            'status' => MailTemplate::ACTIVE,
        ]);

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('Simulated SMTP outage'));
        Log::shouldReceive('warning')->once();

        try {
            $res = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2));
            $res->assertStatus(201);
            $srId = (int) $res->json('data.id');
            $this->assertDatabaseHas('sales_return', ['id' => $srId]);
            $this->assertEquals(47.0, $this->stockQty($this->productA1->id));
        } finally {
            MailTemplate::withoutGlobalScope('tenant')->where('id', $tpl->id)->delete();
        }
    }

    /** Tests 14-18 & 23 & 25: Units, new items on update, parent immutability, multi-item deterministic locks & rollback, partial received_amount, discount_value & note */
    public function test_units_update_new_items_parent_immutability_partial_amounts_and_field_fixes(): void
    {
        Sanctum::actingAs($this->adminA);

        $saleId = $this->createSaleForA([
            ['product_id' => $this->productA1->id, 'product_price' => 25.00, 'quantity' => 5, 'sale_unit' => $this->unitA1->id],
            ['product_id' => $this->productA2->id, 'product_price' => 40.00, 'quantity' => 4, 'sale_unit' => $this->unitA1->id],
        ]);
        $this->assertEquals(45.0, $this->stockQty($this->productA1->id));
        $this->assertEquals(46.0, $this->stockQty($this->productA2->id));

        // 14: Foreign-tenant unit & mismatched unit rejected 422
        $badUnitB = $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 25.00, [
            'sale_return_items' => [['sale_unit' => $this->unitB1->id]],
        ]);
        $this->postJson('/api/sales-return', $badUnitB)->assertStatus(422);

        $badUnitA2 = $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 25.00, [
            'sale_return_items' => [['sale_unit' => $this->unitA2->id]],
        ]);
        $this->postJson('/api/sales-return', $badUnitA2)->assertStatus(422);

        // 25: Note field accepted & persisted cleanly on SaleReturn
        $srCreate = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2, 25.00, [
            'note' => 'Customer damaged box note',
        ]))->assertStatus(201);
        $srId = (int) $srCreate->json('data.id');
        $srItem1Id = (int) SaleReturnItem::where('sale_return_id', $srId)->where('product_id', $this->productA1->id)->value('id');
        $this->assertEquals('Customer damaged box note', $srCreate->json('data.attributes.note'));
        $this->assertEquals(47.0, $this->stockQty($this->productA1->id));

        // 16: Reject foreign customer/warehouse and mismatched same-tenant customer/warehouse on update
        $this->putJson("/api/sales-return/{$srId}", $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2, 25.00, [
            'customer_id' => $this->customerB->id,
            'sale_return_items' => [['sale_return_item_id' => $srItem1Id]],
        ]))->assertStatus(422);
        $this->putJson("/api/sales-return/{$srId}", $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2, 25.00, [
            'customer_id' => $this->customerA2->id,
            'sale_return_items' => [['sale_return_item_id' => $srItem1Id]],
        ]))->assertStatus(422);
        $this->putJson("/api/sales-return/{$srId}", $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2, 25.00, [
            'warehouse_id' => $this->warehouseB1->id,
            'sale_return_items' => [['sale_return_item_id' => $srItem1Id]],
        ]))->assertStatus(422);

        // 15 & 17 & 23: Update existing item qty (2 -> 3, excluding own prior 2 so total 3 <= 5) AND add NEW item (productA2 qty 2 with sale_return_item_id=null)
        $multiUpdatePayload = [
            'sale_id' => $saleId,
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'payment_type' => $this->pmA->id,
            'status' => SaleReturn::RECEIVED,
            'note' => 'Added item 2',
            'sale_return_items' => [
                [
                    'sale_return_item_id' => $srItem1Id,
                    'product_id' => $this->productA1->id,
                    'product_price' => 25.00,
                    'tax_type' => SaleReturn::EXCLUSIVE,
                    'tax_value' => 0,
                    'discount_type' => SaleReturn::FIXED,
                    'discount_value' => 0,
                    'sale_unit' => $this->unitA1->id,
                    'quantity' => 3,
                ],
                [
                    'sale_return_item_id' => null,
                    'product_id' => $this->productA2->id,
                    'product_price' => 40.00,
                    'tax_type' => SaleReturn::EXCLUSIVE,
                    'tax_value' => 0,
                    'discount_type' => SaleReturn::FIXED,
                    'discount_value' => 0,
                    'sale_unit' => $this->unitA1->id,
                    'quantity' => 2,
                ],
            ],
        ];
        $multiRes = $this->putJson("/api/sales-return/{$srId}", $multiUpdatePayload)->assertStatus(200);
        $this->assertCount(2, $multiRes->json('data.attributes.sale_return_items'));
        $this->assertEquals(48.0, $this->stockQty($this->productA1->id)); // 45 + 3
        $this->assertEquals(48.0, $this->stockQty($this->productA2->id)); // 46 + 2

        // 18 & 25: PurchaseReturn preserves partial received_amount + exposes discount_value cleanly
        $purchaseId = $this->createPurchaseForA(Purchase::RECEIVED);
        $prCreate = $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 3, 10.00, [
            'received_amount' => 12.50,
            'payment_status' => PurchaseReturn::UNPAID,
            'purchase_return_items' => [[
                'discount_type' => PurchaseReturn::FIXED,
                'discount_value' => 1.00,
            ]],
        ]))->assertStatus(201);
        $prId = (int) $prCreate->json('data.id');
        $prItemId = (int) PurchaseReturnItem::where('purchase_return_id', $prId)->value('id');

        $editPr = $this->getJson("/api/purchases-return/{$prId}/edit")->assertStatus(200);
        $this->assertEquals(1.0, (float) $editPr->json('data.attributes.purchase_return_items.0.discount_value'));
        $this->assertEquals(12.50, (float) $editPr->json('data.attributes.received_amount'));

        // Unrelated update (omitting received_amount) with payment_status = UNPAID must NOT reset partial received_amount to 0
        $prUpdatePayload = $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 3, 10.00, [
            'notes' => 'Updated note without clearing partial amount',
            'payment_status' => PurchaseReturn::UNPAID,
            'purchase_return_items' => [[
                'purchase_return_item_id' => $prItemId,
                'discount_type' => PurchaseReturn::FIXED,
                'discount_value' => 1.00,
            ]],
        ]);
        unset($prUpdatePayload['received_amount']);
        $prUpdated = $this->putJson("/api/purchases-return/{$prId}", $prUpdatePayload)->assertStatus(200);
        $this->assertEquals(12.50, (float) $prUpdated->json('data.attributes.received_amount'));

        // Foreign supplier/warehouse rejected on update
        $this->putJson("/api/purchases-return/{$prId}", array_replace($prUpdatePayload, ['supplier_id' => $this->supplierA2->id]))->assertStatus(422);
        $this->putJson("/api/purchases-return/{$prId}", array_replace($prUpdatePayload, ['supplier_id' => $this->supplierB->id]))->assertStatus(422);

        // 14 (PurchaseReturn): Foreign-tenant & mismatched units rejected on Purchase Return
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 1, 10.00, [
            'purchase_return_items' => [['purchase_unit' => $this->unitB1->id]],
        ]))->assertStatus(422);
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 1, 10.00, [
            'purchase_return_items' => [['purchase_unit' => $this->unitA2->id]],
        ]))->assertStatus(422);

        // 17 (Atomic multi-item rollback): if increasing A2 succeeds in memory/DB first and reversing A1 fails stock check second, EVERYTHING rolls back
        $this->createSaleForA([[
            'product_id' => $this->productA1->id, 'product_price' => 25.00, 'quantity' => 50, 'sale_unit' => $this->unitA1->id,
        ]]);
        $this->assertEquals(0.0, $this->stockQty($this->productA1->id));
        $srItem2Id = (int) SaleReturnItem::where('sale_return_id', $srId)->where('product_id', $this->productA2->id)->value('id');
        $badMultiEdit = [
            'sale_id' => $saleId,
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA1->id,
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'payment_type' => $this->pmA->id,
            'status' => SaleReturn::RECEIVED,
            'note' => 'Rollback check',
            'sale_return_items' => [
                [
                    'sale_return_item_id' => $srItem2Id,
                    'product_id' => $this->productA2->id,
                    'product_price' => 40.00,
                    'tax_type' => SaleReturn::EXCLUSIVE,
                    'tax_value' => 0,
                    'discount_type' => SaleReturn::FIXED,
                    'discount_value' => 0,
                    'sale_unit' => $this->unitA1->id,
                    'quantity' => 4, // +2 on A2 runs first
                ],
                [
                    'sale_return_item_id' => $srItem1Id,
                    'product_id' => $this->productA1->id,
                    'product_price' => 25.00,
                    'tax_type' => SaleReturn::EXCLUSIVE,
                    'tax_value' => 0,
                    'discount_type' => SaleReturn::FIXED,
                    'discount_value' => 0,
                    'sale_unit' => $this->unitA1->id,
                    'quantity' => 1, // -2 on A1 fails (only 0 left -> 422!)
                ],
            ],
        ];
        $this->putJson("/api/sales-return/{$srId}", $badMultiEdit)->assertStatus(422);
        // Stock and item qty for productA2 must NOT change from 48.0 / 2 (full transaction rollback)
        $this->assertEquals(48.0, $this->stockQty($this->productA2->id));
        $this->assertEquals(2.0, (float) SaleReturnItem::whereKey($srItem2Id)->value('quantity'));
    }

    /** Tests 19-21: FormRequest 422 bounds + price/cost inflation blocked + discount/tax allocation enforced */
    public function test_validation_422_price_cost_inflation_and_discount_tax_allocation_bounds(): void
    {
        Sanctum::actingAs($this->adminA);

        // Discounted/taxed Sale: 2 items @ $100 with 20% item discount -> net 80 -> +10% order tax = 88/unit, order discount 16 -> total (160 - 16) * 1.10 = 158.40 (79.20/unit)
        $saleId = $this->createSaleForA([[
            'product_id' => $this->productA1->id,
            'product_price' => 100.00,
            'discount_type' => Sale::PERCENTAGE,
            'discount_value' => 20,
            'tax_type' => Sale::EXCLUSIVE,
            'tax_value' => 0,
            'quantity' => 2,
            'sale_unit' => $this->unitA1->id,
        ]], 16.0, 10.0);

        // 19: FormRequest 422 checks
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, 3, 1, 100.00))->assertStatus(422); // invalid status
        $this->postJson('/api/sales-return', array_replace($this->saleReturnPayload($saleId), ['sale_return_items' => []]))->assertStatus(422); // empty items
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 0, 100.00))->assertStatus(422); // zero qty
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 100.00, ['discount' => -5]))->assertStatus(422); // negative discount
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, -10.00))->assertStatus(422); // negative price

        // 20: Inflated unit price (> 100.00) rejected
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 120.00))->assertStatus(422);

        // 21: Trying to omit original 20% item discount (claiming 100.00 > 80.00 line sub_total) rejected 422!
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 100.00, [
            'tax_rate' => 10,
            'discount' => 8,
            'sale_return_items' => [['discount_type' => SaleReturn::FIXED, 'discount_value' => 0]],
        ]))->assertStatus(422);

        // 21: Matching item discount (20% -> 80) BUT omitting order discount (0 instead of 8 -> grand_total 88.00 > pro-rata 79.20) rejected 422!
        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 100.00, [
            'tax_rate' => 10,
            'discount' => 0,
            'sale_return_items' => [['discount_type' => SaleReturn::PERCENTAGE, 'discount_value' => 20]],
        ]))->assertStatus(422);

        // 21: Proper discount/tax-allocated return succeeds AND server overrides tampered grand_total
        $validSr = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 100.00, [
            'tax_rate' => 10,
            'discount' => 8,
            'grand_total' => 9999.00, // tampered client value MUST be ignored!
            'sale_return_items' => [['discount_type' => SaleReturn::PERCENTAGE, 'discount_value' => 20]],
        ]))->assertStatus(201);
        $this->assertEqualsWithDelta(79.20, (float) $validSr->json('data.attributes.grand_total'), 0.01);

        // --- Purchase Return cost inflation & discount/tax bounds ---
        $purchaseId = $this->createPurchaseForA(Purchase::RECEIVED, [[
            'product_id' => $this->productA1->id,
            'product_cost' => 50.00,
            'discount_type' => Purchase::FIXED,
            'discount_value' => 10.00, // net 40/unit
            'quantity' => 2,
            'purchase_unit' => $this->unitA1->id,
        ]]);

        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, 3, 1, 40.00))->assertStatus(422);
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 1, 60.00))->assertStatus(422); // > cost 50
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 1, 50.00, [
            'purchase_return_items' => [['discount_type' => PurchaseReturn::FIXED, 'discount_value' => 0]],
        ]))->assertStatus(422); // exceeds net 40/unit

        // Valid lower restocking credit (30.00 < 40.00) accepted cleanly
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($purchaseId, PurchaseReturn::COMPLETED, 1, 30.00))->assertStatus(201);
    }

    /** Test 22: Mixed linked & unlinked standalone Purchase Returns share one cumulative quota (D2) */
    public function test_mixed_linked_and_unlinked_purchase_returns_share_cumulative_quota(): void
    {
        Sanctum::actingAs($this->adminA);

        // Two RECEIVED purchases from supplierA: P1 (qty 5), P2 (qty 5) -> total 10
        $p1Id = $this->createPurchaseForA(Purchase::RECEIVED, [['product_id' => $this->productA1->id, 'product_cost' => 10.00, 'quantity' => 5, 'purchase_unit' => $this->unitA1->id]]);
        $p2Id = $this->createPurchaseForA(Purchase::RECEIVED, [['product_id' => $this->productA1->id, 'product_cost' => 10.00, 'quantity' => 5, 'purchase_unit' => $this->unitA1->id]]);

        // Pending purchase P3 (qty 10) must NOT count toward eligible purchased qty!
        $this->createPurchaseForA(Purchase::PENDING, [['product_id' => $this->productA1->id, 'product_cost' => 10.00, 'quantity' => 10, 'purchase_unit' => $this->unitA1->id]]);

        // 1. Linked return against P1 for qty 4 -> ok (remaining on P1 = 1, total pool remaining = 6)
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($p1Id, PurchaseReturn::COMPLETED, 4))->assertStatus(201);

        // 2. Standalone unlinked return (purchase_id = null) for qty 5 -> ok (total returned = 9, pool remaining = 1)
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload(null, PurchaseReturn::COMPLETED, 5))->assertStatus(201);

        // 3. Trying linked return on P2 for qty 2 (9 + 2 = 11 > 10 pool total, even though P2 itself had 0 linked returns!) -> MUST FAIL 422!
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($p2Id, PurchaseReturn::COMPLETED, 2))->assertStatus(422);

        // 4. Exact remaining 1 on P2 succeeds (total 10 == 10) -> then any additional return fails 422
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($p2Id, PurchaseReturn::COMPLETED, 1))->assertStatus(201);
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload(null, PurchaseReturn::COMPLETED, 1))->assertStatus(422);
    }

    /** Microfix 1: Deleting a RECEIVED Purchase is blocked when standalone unlinked returns rely on its quota, without blocking unrelated purchases */
    public function test_microfix1_purchase_delete_protects_standalone_return_quota_and_allows_unrelated(): void
    {
        Sanctum::actingAs($this->adminA);

        // Supplier A sells Product A1 (qty 5) via P1, and Supplier A2 supplies Product A1 (qty 10) so physical stock never fails reversal
        $p1Id = $this->createPurchaseForA(Purchase::RECEIVED, [['product_id' => $this->productA1->id, 'product_cost' => 10.00, 'quantity' => 5, 'purchase_unit' => $this->unitA1->id]]);
        $this->createPurchaseForA(Purchase::RECEIVED, [['product_id' => $this->productA1->id, 'product_cost' => 10.00, 'quantity' => 10, 'purchase_unit' => $this->unitA1->id]], 0, 0, $this->supplierA2->id);

        // Standalone unlinked Purchase Return against Supplier A for Product A1 (qty 3)
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload(null, PurchaseReturn::COMPLETED, 3))->assertStatus(201);

        // Unrelated RECEIVED Purchase from Supplier A for Product A2 (qty 5) CAN be deleted cleanly
        $pUnrelatedId = $this->createPurchaseForA(Purchase::RECEIVED, [['product_id' => $this->productA2->id, 'product_cost' => 15.00, 'quantity' => 5, 'purchase_unit' => $this->unitA1->id]]);
        $this->deleteJson("/api/purchases/{$pUnrelatedId}")->assertStatus(200);
        $this->assertDatabaseMissing('purchases', ['id' => $pUnrelatedId]);

        // Deleting P1 MUST BE BLOCKED 422 because remaining Supplier A received qty for Product A1 would become 0 (< 3 unlinked returned)!
        $this->deleteJson("/api/purchases/{$p1Id}")->assertStatus(422);
        $this->assertDatabaseHas('purchases', ['id' => $p1Id]);

        // Create second RECEIVED Purchase P2 from Supplier A for Product A1 (qty 5) -> now removing P1 still leaves P2 (5 >= 3), so P1 CAN be deleted!
        $p2Id = $this->createPurchaseForA(Purchase::RECEIVED, [['product_id' => $this->productA1->id, 'product_cost' => 10.00, 'quantity' => 5, 'purchase_unit' => $this->unitA1->id]]);
        $this->deleteJson("/api/purchases/{$p1Id}")->assertStatus(200);
        $this->assertDatabaseMissing('purchases', ['id' => $p1Id]);
        $this->assertDatabaseHas('purchases', ['id' => $p2Id]);
    }

    /** Microfix 2: Sale Return PAID creation/update consistency, strict overpayment rejection (+0.01/+0.02), partial & unpaid preservation */
    public function test_microfix2_sale_return_paid_creation_consistency_and_partial_preservation(): void
    {
        Sanctum::actingAs($this->adminA);

        $saleId = $this->createSaleForA([[
            'product_id' => $this->productA1->id, 'product_price' => 25.00, 'quantity' => 8, 'sale_unit' => $this->unitA1->id,
        ]]);

        // 1. Create with payment_status = PAID and omitted paid_amount (with tampered grand_total) -> sets paid_amount = server grand_total (50.00)
        $paidPayload = $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2, 25.00, [
            'payment_status' => SaleReturn::PAID,
            'grand_total' => 9999.00,
        ]);
        unset($paidPayload['paid_amount']);
        $paidRes = $this->postJson('/api/sales-return', $paidPayload)->assertStatus(201);
        $this->assertEquals(SaleReturn::PAID, (int) $paidRes->json('data.attributes.payment_status'));
        $this->assertEquals(50.00, (float) $paidRes->json('data.attributes.grand_total'));
        $this->assertEquals(50.00, (float) $paidRes->json('data.attributes.paid_amount'));

        // 2. Partial payment preserved cleanly on create (payment_status = UNPAID, paid_amount = 15.00 on 50.00 return)
        $partialRes = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2, 25.00, [
            'payment_status' => SaleReturn::UNPAID,
            'paid_amount' => 15.00,
        ]))->assertStatus(201);
        $partialId = (int) $partialRes->json('data.id');
        $partialItemId = (int) SaleReturnItem::where('sale_return_id', $partialId)->value('id');
        $this->assertEquals(SaleReturn::UNPAID, (int) $partialRes->json('data.attributes.payment_status'));
        $this->assertEquals(15.00, (float) $partialRes->json('data.attributes.paid_amount'));

        // Unrelated update passing paid_amount = 0 preserves existing partial 15.00
        $partialUnrelatedUpdate = $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 2, 25.00, [
            'payment_status' => SaleReturn::UNPAID,
            'paid_amount' => 0,
            'note' => 'Keep partial 15.00',
            'sale_return_items' => [['sale_return_item_id' => $partialItemId]],
        ]);
        $partialAfterRes = $this->putJson("/api/sales-return/{$partialId}", $partialUnrelatedUpdate)->assertStatus(200);
        $this->assertEquals(15.00, (float) $partialAfterRes->json('data.attributes.paid_amount'));

        // 3. Normal unpaid creation (payment_status = UNPAID, paid_amount = 0) stays 0
        $unpaidRes = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::PENDING, 1, 25.00, [
            'payment_status' => SaleReturn::UNPAID,
            'paid_amount' => 0,
        ]))->assertStatus(201);
        $this->assertEquals(SaleReturn::UNPAID, (int) $unpaidRes->json('data.attributes.payment_status'));
        $this->assertEquals(0.0, (float) $unpaidRes->json('data.attributes.paid_amount'));

        // 4. Exact-boundary create: grand_total 25.00, paid_amount 25.00 -> accepted; 25.01 & 25.02 -> 422 rejected
        $exactRes = $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 25.00, [
            'payment_status' => SaleReturn::PAID,
            'paid_amount' => 25.00,
        ]))->assertStatus(201);
        $exactId = (int) $exactRes->json('data.id');
        $exactItemId = (int) SaleReturnItem::where('sale_return_id', $exactId)->value('id');
        $this->assertEquals(25.00, (float) $exactRes->json('data.attributes.grand_total'));
        $this->assertEquals(25.00, (float) $exactRes->json('data.attributes.paid_amount'));

        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 25.00, [
            'payment_status' => SaleReturn::PAID,
            'paid_amount' => 25.01,
        ]))->assertStatus(422);

        $this->postJson('/api/sales-return', $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 25.00, [
            'payment_status' => SaleReturn::PAID,
            'paid_amount' => 25.02,
        ]))->assertStatus(422);

        // 5. Update path strict overpayment checks on grand_total = 25.00
        $updateBase = $this->saleReturnPayload($saleId, SaleReturn::RECEIVED, 1, 25.00, [
            'payment_status' => SaleReturn::PAID,
            'paid_amount' => 25.00,
            'sale_return_items' => [['sale_return_item_id' => $exactItemId]],
        ]);
        $this->putJson("/api/sales-return/{$exactId}", $updateBase)->assertStatus(200);

        $this->putJson("/api/sales-return/{$exactId}", array_replace($updateBase, ['paid_amount' => 25.01]))->assertStatus(422);
        $this->putJson("/api/sales-return/{$exactId}", array_replace($updateBase, ['paid_amount' => 25.02, 'payment_status' => SaleReturn::UNPAID]))->assertStatus(422);
        $this->assertEquals(25.00, (float) SaleReturn::withoutGlobalScope('tenant')->whereKey($exactId)->value('paid_amount'));
    }

    /** Microfix 3: Standalone Purchase Return conservative fail-closed multi-cost validation and exhausted-lot exclusion */
    public function test_microfix3_standalone_purchase_return_conservative_cost_attribution(): void
    {
        Sanctum::actingAs($this->adminA);

        // Two RECEIVED purchases with distinct costs from Supplier A: P1 (3 @ $10, discount 0), P2 (3 @ $20, discount 0)
        $p1Id = $this->createPurchaseForA(Purchase::RECEIVED, [[
            'product_id' => $this->productA1->id, 'product_cost' => 10.00, 'quantity' => 3, 'purchase_unit' => $this->unitA1->id,
        ]]);
        $p2Id = $this->createPurchaseForA(Purchase::RECEIVED, [[
            'product_id' => $this->productA1->id, 'product_cost' => 20.00, 'quantity' => 3, 'purchase_unit' => $this->unitA1->id,
        ]]);

        // 1. Both unexhausted (costs $10 and $20): standalone unlinked return claiming higher ambiguous cost $20 fails closed 422!
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload(null, PurchaseReturn::COMPLETED, 1, 20.00))->assertStatus(422);

        // 2. Standalone unlinked return at conservative minimum eligible cost ($10 <= min) succeeds!
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload(null, PurchaseReturn::COMPLETED, 1, 10.00))->assertStatus(201);

        // 3. Explicit linked return against P2 at $20 succeeds for P2's exact lot cost ($20), exhausting P2's remaining 3 units!
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload($p2Id, PurchaseReturn::COMPLETED, 3, 20.00))->assertStatus(201);

        // 4. Now P2 (@ $20) is exhausted by linked return; only P1 (@ $10) remains -> standalone claiming $20 STILL rejected 422, while $10 succeeds!
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload(null, PurchaseReturn::COMPLETED, 1, 20.00))->assertStatus(422);
        $this->postJson('/api/purchases-return', $this->purchaseReturnPayload(null, PurchaseReturn::COMPLETED, 1, 10.00))->assertStatus(201);
        $this->assertDatabaseHas('purchases', ['id' => $p1Id]);
    }
}
