<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\MultiTenant;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Transfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FreshMartPostQaRemediationTest extends TestCase
{
    use DatabaseTransactions;

    private function createBranchEnvironment(string $suffix): array
    {
        $owner = User::create([
            'first_name' => 'Owner',
            'last_name' => $suffix,
            'email' => "owner.{$suffix}." . Str::random(5) . '@example.test',
            'phone' => '017' . rand(10000000, 99999999),
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
            'status' => 1,
        ]);
        $store = Store::create([
            'name' => 'QA Store ' . $suffix,
            'user_id' => $owner->id,
            'status' => 1,
        ]);
        $tenant = MultiTenant::create(['store_id' => $store->id]);
        $store->update(['tenant_id' => $tenant->id]);
        $owner->update(['tenant_id' => $tenant->id]);
        UserStore::create(['user_id' => $owner->id, 'store_id' => $store->id]);

        $plan = Plan::first();
        if ($plan) {
            Subscription::create([
                'user_id' => $owner->id,
                'plan_id' => $plan->id,
                'payment_type' => Subscription::TYPE_FREE,
                'plan_amount' => 0,
                'payable_amount' => 0,
                'plan_frequency' => Plan::WEEKLY,
                'start_date' => now(),
                'end_date' => now()->addDays(30),
                'status' => Subscription::ACTIVE,
            ]);
        }

        $adminRole = Role::withoutGlobalScopes()->where('name', Role::ADMIN)->first();
        if ($adminRole) {
            $owner->assignRole($adminRole);
        }

        Sanctum::actingAs($owner);

        $w1 = Warehouse::create([
            'tenant_id' => $tenant->id,
            'name' => "Main WH {$suffix}",
            'phone' => '01700000001',
            'country' => 'Bangladesh',
            'city' => 'Kushtia',
            'email' => "w1.{$suffix}@example.test",
            'zip_code' => '7000',
        ]);
        $w2 = Warehouse::create([
            'tenant_id' => $tenant->id,
            'name' => "Floor WH {$suffix}",
            'phone' => '01700000002',
            'country' => 'Bangladesh',
            'city' => 'Kushtia',
            'email' => "w2.{$suffix}@example.test",
            'zip_code' => '7000',
        ]);

        $baseUnit = BaseUnit::create([
            'tenant_id' => $tenant->id,
            'name' => 'kilogram',
            'is_default' => 1,
        ]);
        $unit = Unit::create([
            'tenant_id' => $tenant->id,
            'name' => '1 Kilogram',
            'short_name' => '1kg',
            'base_unit' => $baseUnit->id,
        ]);
        $category = ProductCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Grocery ' . $suffix,
        ]);
        $brand = Brand::create([
            'tenant_id' => $tenant->id,
            'name' => 'Brand ' . $suffix,
        ]);

        $code = 'REMED-' . strtoupper(Str::random(6));
        $mainProduct = MainProduct::create([
            'tenant_id' => $tenant->id,
            'name' => 'Remediation Rice ' . $suffix,
            'code' => $code,
            'product_unit' => $unit->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);
        $product = Product::create([
            'tenant_id' => $tenant->id,
            'main_product_id' => $mainProduct->id,
            'name' => 'Remediation Rice ' . $suffix,
            'code' => $code,
            'product_code' => $code,
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_cost' => 80,
            'product_price' => 100,
            'product_unit' => $baseUnit->id,
            'sale_unit' => $unit->id,
            'purchase_unit' => $unit->id,
            'stock_alert' => 5,
            'order_tax' => 0,
            'tax_type' => 1,
            'barcode_symbol' => 1,
        ]);

        ManageStock::create([
            'tenant_id' => $tenant->id,
            'warehouse_id' => $w1->id,
            'product_id' => $product->id,
            'quantity' => 50,
        ]);

        $customer = Customer::create([
            'tenant_id' => $tenant->id,
            'name' => 'Customer ' . $suffix,
            'email' => "cust.{$suffix}." . Str::random(4) . '@example.test',
            'phone' => '018' . rand(10000000, 99999999),
            'country' => 'Bangladesh',
            'city' => 'Kushtia',
            'address' => 'Kushtia',
        ]);

        $paymentMethod = PaymentMethod::create([
            'tenant_id' => $tenant->id,
            'name' => 'Cash',
            'status' => 1,
        ]);

        $expenseCategory = ExpenseCategory::create([
            'tenant_id' => $tenant->id,
            'name' => 'Utilities ' . $suffix,
        ]);

        return compact(
            'tenant', 'store', 'owner', 'w1', 'w2',
            'baseUnit', 'unit', 'mainProduct', 'product',
            'customer', 'paymentMethod', 'expenseCategory'
        );
    }

    public function test_phase1_cross_tenant_destination_warehouse_transfer_is_rejected(): void
    {
        $branchA = $this->createBranchEnvironment('A');
        $branchB = $this->createBranchEnvironment('B');

        Sanctum::actingAs($branchA['owner']);

        $beforeCount = Transfer::withoutGlobalScopes()->count();

        $res = $this->postJson('/api/transfers', [
            'date' => now()->format('Y-m-d'),
            'from_warehouse_id' => $branchA['w1']->id,
            'to_warehouse_id' => $branchB['w1']->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 500,
            'status' => 1,
            'transfer_items' => [[
                'product_id' => $branchA['product']->id,
                'product_price' => 100,
                'net_unit_price' => 100,
                'tax_type' => 1,
                'tax_value' => 0,
                'tax_amount' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'discount_amount' => 0,
                'quantity' => 5,
                'sub_total' => 500,
            ]],
        ]);

        $res->assertStatus(422);
        $this->assertEquals($beforeCount, Transfer::withoutGlobalScopes()->count());
        $this->assertEquals(
            50.0,
            (float) ManageStock::withoutGlobalScopes()
                ->where('warehouse_id', $branchA['w1']->id)
                ->where('product_id', $branchA['product']->id)
                ->value('quantity')
        );
        $this->assertDatabaseMissing('manage_stocks', [
            'warehouse_id' => $branchB['w1']->id,
            'product_id' => $branchA['product']->id,
        ]);
    }

    public function test_phase2_inventory_officer_cannot_mutate_expenses_while_owner_can(): void
    {
        $branch = $this->createBranchEnvironment('EXP');

        $invRole = \Spatie\Permission\Models\Role::create([
            'name' => 'inv_officer_' . Str::random(5),
            'display_name' => 'Inventory Officer QA',
            'guard_name' => 'web',
            'tenant_id' => $branch['tenant']->id,
        ]);
        $reportPerm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_reports', 'guard_name' => 'web']);
        $invRole->givePermissionTo($reportPerm);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $invUser = User::create([
            'first_name' => 'Inventory',
            'last_name' => 'Officer',
            'email' => 'inv.qa.' . Str::random(5) . '@example.test',
            'phone' => '017' . rand(10000000, 99999999),
            'password' => Hash::make('Password123!'),
            'email_verified_at' => now(),
            'tenant_id' => $branch['tenant']->id,
            'status' => 1,
        ]);
        UserStore::create(['user_id' => $invUser->id, 'store_id' => $branch['store']->id]);
        $invUser->assignRole($invRole);
        $invUser->givePermissionTo($reportPerm);

        // Unauthorized Inventory Officer: can read for reports, MUST get 403 on create
        Sanctum::actingAs($invUser);
        $getExp = $this->getJson('/api/expenses');
        $this->assertEquals(200, $getExp->status(), $getExp->getContent());
        $this->postJson('/api/expenses', [
            'date' => now()->format('Y-m-d'),
            'warehouse_id' => $branch['w1']->id,
            'expense_category_id' => $branch['expenseCategory']->id,
            'amount' => 250,
            'title' => 'Unauthorized Expense',
        ])->assertStatus(403);

        // Authorized Owner: CAN create, update, delete expense
        Sanctum::actingAs($branch['owner']);
        $createRes = $this->postJson('/api/expenses', [
            'date' => now()->format('Y-m-d'),
            'warehouse_id' => $branch['w1']->id,
            'expense_category_id' => $branch['expenseCategory']->id,
            'amount' => 300,
            'title' => 'Authorized Owner Expense',
        ]);
        $createRes->assertStatus(201);
        $expenseId = $createRes->json('data.id');
        $this->assertNotNull($expenseId);

        // Unauthorized Inventory Officer also blocked from deleting Owner's expense
        Sanctum::actingAs($invUser);
        $this->deleteJson('/api/expenses/' . $expenseId)->assertStatus(403);
    }

    public function test_phase3_stock_movements_logged_for_adjustment_transfer_and_sale_return(): void
    {
        $branch = $this->createBranchEnvironment('LEDGER');
        Sanctum::actingAs($branch['owner']);

        $pid = $branch['product']->id;
        $w1 = $branch['w1']->id;
        $w2 = $branch['w2']->id;

        // 1. Stock Adjustment (-5 from w1: 50 -> 45)
        $adjRes = $this->postJson('/api/adjustments', [
            'date' => now()->format('Y-m-d'),
            'warehouse_id' => $w1,
            'adjustment_items' => [[
                'product_id' => $pid,
                'quantity' => 5,
                'method_type' => AdjustmentItem::METHOD_SUBTRACTION,
            ]],
        ]);
        $this->assertEquals(201, $adjRes->status(), $adjRes->getContent());
        $adjId = $adjRes->json('data.id');

        $adjMove = StockMovement::withoutGlobalScopes()
            ->where('product_id', $pid)
            ->where('reference_type', Adjustment::class)
            ->where('reference_id', $adjId)
            ->get();
        $this->assertCount(1, $adjMove);
        $this->assertEquals('adjustment_sub', $adjMove[0]->type);
        $this->assertEquals(-5.0, (float) $adjMove[0]->quantity_change);
        $this->assertEquals(50.0, (float) $adjMove[0]->quantity_before);
        $this->assertEquals(45.0, (float) $adjMove[0]->quantity_after);

        // 2. Intra-Branch Transfer (15 from w1 -> w2: w1 45->30, w2 0->15)
        $trRes = $this->postJson('/api/transfers', [
            'date' => now()->format('Y-m-d'),
            'from_warehouse_id' => $w1,
            'to_warehouse_id' => $w2,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 1500,
            'status' => 1,
            'transfer_items' => [[
                'product_id' => $pid,
                'product_price' => 100,
                'net_unit_price' => 100,
                'tax_type' => 1,
                'tax_value' => 0,
                'tax_amount' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'discount_amount' => 0,
                'quantity' => 15,
                'sub_total' => 1500,
            ]],
        ]);
        $trRes->assertStatus(201);
        $trId = $trRes->json('data.id');

        $trOut = StockMovement::withoutGlobalScopes()
            ->where('product_id', $pid)
            ->where('reference_type', Transfer::class)
            ->where('reference_id', $trId)
            ->where('type', 'transfer_out')
            ->get();
        $trIn = StockMovement::withoutGlobalScopes()
            ->where('product_id', $pid)
            ->where('reference_type', Transfer::class)
            ->where('reference_id', $trId)
            ->where('type', 'transfer_in')
            ->get();
        $this->assertCount(1, $trOut);
        $this->assertCount(1, $trIn);
        $this->assertEquals(-15.0, (float) $trOut[0]->quantity_change);
        $this->assertEquals(45.0, (float) $trOut[0]->quantity_before);
        $this->assertEquals(30.0, (float) $trOut[0]->quantity_after);
        $this->assertEquals(15.0, (float) $trIn[0]->quantity_change);
        $this->assertEquals(0.0, (float) $trIn[0]->quantity_before);
        $this->assertEquals(15.0, (float) $trIn[0]->quantity_after);

        // 3. Sale (-10 from w1: 30->20) + Sales Return (+4 to w1: 20->24)
        $saleRes = $this->postJson('/api/sales', [
            'date' => now()->format('Y-m-d'),
            'customer_id' => $branch['customer']->id,
            'warehouse_id' => $w1,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 1000,
            'received_amount' => 1000,
            'paid_amount' => 1000,
            'payment_type' => $branch['paymentMethod']->id,
            'payment_status' => 1,
            'status' => 1,
            'is_sale_created' => true,
            'payment_details' => [['payment_type' => ['value' => $branch['paymentMethod']->id], 'amount' => 1000]],
            'sale_items' => [[
                'product_id' => $pid,
                'product_price' => 100,
                'net_unit_price' => 100,
                'tax_type' => 1,
                'tax_value' => 0,
                'tax_amount' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'discount_amount' => 0,
                'sale_unit' => $branch['unit']->id,
                'quantity' => 10,
                'sub_total' => 1000,
            ]],
        ]);
        $saleRes->assertStatus(201);
        $saleId = $saleRes->json('data.id');

        $retRes = $this->postJson('/api/sales-return', [
            'date' => now()->format('Y-m-d'),
            'customer_id' => $branch['customer']->id,
            'warehouse_id' => $w1,
            'sale_id' => $saleId,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 400,
            'paid_amount' => 400,
            'payment_type' => $branch['paymentMethod']->id,
            'payment_status' => 1,
            'status' => 1,
            'sale_return_items' => [[
                'product_id' => $pid,
                'product_price' => 100,
                'net_unit_price' => 100,
                'tax_type' => 1,
                'tax_value' => 0,
                'tax_amount' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'discount_amount' => 0,
                'sale_unit' => $branch['unit']->id,
                'quantity' => 4,
                'sub_total' => 400,
            ]],
        ]);
        $retRes->assertStatus(201);
        $retId = $retRes->json('data.id');

        $retMove = StockMovement::withoutGlobalScopes()
            ->where('product_id', $pid)
            ->where('reference_type', SaleReturn::class)
            ->where('reference_id', $retId)
            ->get();
        $this->assertCount(1, $retMove);
        $this->assertEquals('sale_return', $retMove[0]->type);
        $this->assertEquals(4.0, (float) $retMove[0]->quantity_change);
        $this->assertEquals(20.0, (float) $retMove[0]->quantity_before);
        $this->assertEquals(24.0, (float) $retMove[0]->quantity_after);

        // Final balance verification against movement history
        $w1Actual = (float) ManageStock::withoutGlobalScopes()->where('warehouse_id', $w1)->where('product_id', $pid)->value('quantity');
        $w2Actual = (float) ManageStock::withoutGlobalScopes()->where('warehouse_id', $w2)->where('product_id', $pid)->value('quantity');
        $this->assertEquals(24.0, $w1Actual);
        $this->assertEquals(15.0, $w2Actual);

        $w1NetMovements = (float) StockMovement::withoutGlobalScopes()->where('warehouse_id', $w1)->where('product_id', $pid)->sum('quantity_change');
        $w2NetMovements = (float) StockMovement::withoutGlobalScopes()->where('warehouse_id', $w2)->where('product_id', $pid)->sum('quantity_change');
        $this->assertEquals(50.0 + $w1NetMovements, $w1Actual);
        $this->assertEquals(0.0 + $w2NetMovements, $w2Actual);
    }

    public function test_phase4_main_product_resolves_unit_id_to_base_unit_name(): void
    {
        $branch = $this->createBranchEnvironment('UNIT');
        Sanctum::actingAs($branch['owner']);

        $unitData = $branch['mainProduct']->getProductUnitName();
        $this->assertIsArray($unitData);
        $this->assertEquals('kilogram', $unitData['name']);
    }
}
