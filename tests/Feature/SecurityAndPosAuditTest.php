<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\MultiTenant;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\PurchaseRepository;
use App\Repositories\PurchaseReturnRepository;
use App\Repositories\SaleRepository;
use App\Repositories\SaleReturnRepository;
use App\Repositories\TransferRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class SecurityAndPosAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function createTenantWithUser(string $roleName = Role::ADMIN, array $permissions = []): array
    {
        $user = User::create([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'user_' . uniqid() . '@example.com',
            'password' => Hash::make('secret123'),
            'email_verified_at' => now(),
            'status' => 1,
        ]);

        $store = Store::create([
            'name' => 'Store ' . uniqid(),
            'user_id' => $user->id,
        ]);
        $tenant = MultiTenant::create(['store_id' => $store->id]);
        $store->update(['tenant_id' => $tenant->id]);
        $user->update(['tenant_id' => $tenant->id]);

        $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->assignRole($role);
        foreach ($permissions as $permName) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
            $user->givePermissionTo($perm);
        }

        $plan = Plan::first();
        if ($plan) {
            Subscription::create([
                'user_id' => $user->id,
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

        return [$user, $store, $tenant];
    }

    private function createInventoryFixtures(string $tenantId): array
    {
        $warehouse = Warehouse::create([
            'tenant_id' => $tenantId,
            'name' => 'WH Main ' . uniqid(),
            'phone' => '1234567890',
            'country' => 'US',
            'city' => 'NYC',
            'email' => 'wh_' . uniqid() . '@example.com',
        ]);
        $warehouse2 = Warehouse::create([
            'tenant_id' => $tenantId,
            'name' => 'WH Secondary ' . uniqid(),
            'phone' => '0987654321',
            'country' => 'US',
            'city' => 'LA',
            'email' => 'wh2_' . uniqid() . '@example.com',
        ]);
        $customer = Customer::create([
            'tenant_id' => $tenantId,
            'name' => 'Walk-in Customer',
            'email' => 'cust_' . uniqid() . '@example.com',
            'phone' => '1112223333',
            'country' => 'US',
            'city' => 'NYC',
            'address' => '123 Main St',
        ]);
        $supplier = Supplier::create([
            'tenant_id' => $tenantId,
            'name' => 'Primary Supplier',
            'email' => 'supp_' . uniqid() . '@example.com',
            'phone' => '4445556666',
            'country' => 'US',
            'city' => 'NYC',
            'address' => '456 Supply Rd',
        ]);
        $category = ProductCategory::create(['tenant_id' => $tenantId, 'name' => 'General ' . uniqid()]);
        $brand = Brand::create(['tenant_id' => $tenantId, 'name' => 'Brand ' . uniqid()]);
        $unit = Unit::create(['tenant_id' => $tenantId, 'name' => 'Piece ' . uniqid(), 'short_name' => 'pc', 'base_unit' => 1]);
        $mainProduct = MainProduct::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Product',
            'code' => 'SKU-' . uniqid(),
            'product_unit' => (string) $unit->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);
        $product = Product::create([
            'tenant_id' => $tenantId,
            'main_product_id' => $mainProduct->id,
            'name' => 'Test Product',
            'code' => $mainProduct->code,
            'product_code' => $mainProduct->code,
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_cost' => 10.00,
            'product_price' => 25.00,
            'product_unit' => (string) $unit->id,
            'sale_unit' => (string) $unit->id,
            'purchase_unit' => (string) $unit->id,
            'stock_alert' => 5,
            'barcode_symbol' => Product::CODE128,
        ]);
        $cashMethod = PaymentMethod::create(['tenant_id' => $tenantId, 'name' => 'Cash', 'status' => 1]);
        $cardMethod = PaymentMethod::create(['tenant_id' => $tenantId, 'name' => 'Card', 'status' => 1]);

        return compact('warehouse', 'warehouse2', 'customer', 'supplier', 'product', 'cashMethod', 'cardMethod', 'unit');
    }

    public function test_upgrade_route_is_blocked_when_upgrade_mode_is_false(): void
    {
        config(['app.upgrade_mode' => false]);
        $this->get('/upgrade-to-v1-2-0')->assertStatus(403);
    }

    public function test_cache_clear_requires_authentication(): void
    {
        $this->getJson('/api/cache-clear')->assertStatus(401);
    }

    public function test_validate_auth_token_returns_401_without_bearer_token(): void
    {
        $this->postJson('/api/validate-auth-token')
            ->assertStatus(401)
            ->assertJson(['success' => false]);
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->postJson('/api/login', [])->assertStatus(422);
    }

    public function test_user_cannot_switch_to_or_delete_another_tenants_store(): void
    {
        [$userA] = $this->createTenantWithUser();
        [$userB, $storeB] = $this->createTenantWithUser();

        $this->actingAs($userA, 'sanctum')
            ->postJson('/api/change-store/' . $storeB->id)
            ->assertStatus(403);

        $this->actingAs($userA, 'sanctum')
            ->deleteJson('/api/stores/' . $storeB->id)
            ->assertStatus(403);
    }

    public function test_permission_restriction_blocks_unauthorized_user_from_reports_and_superadmin_translations(): void
    {
        [$basicUser] = $this->createTenantWithUser('cashier_' . uniqid(), []);

        $this->actingAs($basicUser, 'sanctum')
            ->getJson('/api/stock-valuation')
            ->assertStatus(403);

        $this->actingAs($basicUser, 'sanctum')
            ->postJson('/api/sadmin/languages/translation/1/update', [])
            ->assertStatus(403);
    }

    public function test_purchase_received_increments_stock_and_records_movement_while_pending_does_not(): void
    {
        [$user, , $tenant] = $this->createTenantWithUser();
        $this->actingAs($user, 'sanctum');
        $f = $this->createInventoryFixtures($tenant->id);

        /** @var PurchaseRepository $purchaseRepo */
        $purchaseRepo = app(PurchaseRepository::class);

        // PENDING purchase must NOT add stock
        $purchaseRepo->storePurchase([
            'supplier_id' => $f['supplier']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => Purchase::PENDING,
            'payment_status' => Purchase::UNPAID,
            'purchase_items' => [[
                'product_id' => $f['product']->id,
                'product_cost' => 10,
                'tax_type' => Purchase::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Purchase::FIXED,
                'discount_value' => 0,
                'purchase_unit' => $f['unit']->id,
                'quantity' => 5,
            ]],
        ]);
        $this->assertNull(ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->first());

        // RECEIVED purchase MUST add stock & create StockMovement
        $purchaseRepo->storePurchase([
            'supplier_id' => $f['supplier']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => Purchase::RECEIVED,
            'payment_status' => Purchase::PAID,
            'purchase_items' => [[
                'product_id' => $f['product']->id,
                'product_cost' => 10,
                'tax_type' => Purchase::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Purchase::FIXED,
                'discount_value' => 0,
                'purchase_unit' => $f['unit']->id,
                'quantity' => 10,
            ]],
        ]);

        $stock = ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->first();
        $this->assertNotNull($stock);
        $this->assertEquals(10.0, (float) $stock->quantity);
        $this->assertTrue(StockMovement::where('product_id', $f['product']->id)->where('type', 'purchase')->exists());
    }

    public function test_pos_checkout_split_payment_overpayment_stock_deduction_and_negative_stock_prevention(): void
    {
        [$user, , $tenant] = $this->createTenantWithUser();
        $this->actingAs($user, 'sanctum');
        $f = $this->createInventoryFixtures($tenant->id);

        ManageStock::create([
            'warehouse_id' => $f['warehouse']->id,
            'product_id' => $f['product']->id,
            'quantity' => 5,
        ]);

        /** @var SaleRepository $saleRepo */
        $saleRepo = app(SaleRepository::class);

        // Cash overpayment (total 50, paid 60) -> must be marked PAID, stock reduced 5 -> 3
        $sale = $saleRepo->storeSale([
            'customer_id' => $f['customer']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => Sale::COMPLETED,
            'payment_status' => Sale::PAID,
            'sale_items' => [[
                'product_id' => $f['product']->id,
                'product_price' => 25,
                'tax_type' => Sale::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Sale::FIXED,
                'discount_value' => 0,
                'sale_unit' => $f['unit']->id,
                'quantity' => 2,
            ]],
            'payment_details' => [[
                'amount' => 60,
                'payment_type' => ['value' => $f['cashMethod']->id],
            ]],
        ]);

        $this->assertEquals(Sale::PAID, $sale->fresh()->payment_status);
        $this->assertEquals(3.0, (float) ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->value('quantity'));

        // Split payment (total 50 = 20 cash + 30 card) -> PAID, stock reduced 3 -> 1
        $splitSale = $saleRepo->storeSale([
            'customer_id' => $f['customer']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => Sale::COMPLETED,
            'payment_status' => Sale::PAID,
            'sale_items' => [[
                'product_id' => $f['product']->id,
                'product_price' => 25,
                'tax_type' => Sale::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Sale::FIXED,
                'discount_value' => 0,
                'sale_unit' => $f['unit']->id,
                'quantity' => 2,
            ]],
            'payment_details' => [
                ['amount' => 20, 'payment_type' => ['value' => $f['cashMethod']->id]],
                ['amount' => 30, 'payment_type' => ['value' => $f['cardMethod']->id]],
            ],
        ]);
        $this->assertEquals(Sale::PAID, $splitSale->fresh()->payment_status);
        $this->assertEquals(2, $splitSale->payments()->count());
        $this->assertEquals(1.0, (float) ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->value('quantity'));

        // Negative stock prevention (only 1 remaining, attempting to sell 5)
        $this->expectException(UnprocessableEntityHttpException::class);
        $saleRepo->storeSale([
            'customer_id' => $f['customer']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => Sale::COMPLETED,
            'payment_status' => Sale::UNPAID,
            'sale_items' => [[
                'product_id' => $f['product']->id,
                'product_price' => 25,
                'tax_type' => Sale::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Sale::FIXED,
                'discount_value' => 0,
                'sale_unit' => $f['unit']->id,
                'quantity' => 5,
            ]],
        ]);
    }

    public function test_stock_transfer_blocks_same_warehouse_and_moves_stock_between_warehouses(): void
    {
        [$user, , $tenant] = $this->createTenantWithUser();
        $this->actingAs($user, 'sanctum');
        $f = $this->createInventoryFixtures($tenant->id);

        ManageStock::create([
            'warehouse_id' => $f['warehouse']->id,
            'product_id' => $f['product']->id,
            'quantity' => 10,
        ]);

        /** @var TransferRepository $transferRepo */
        $transferRepo = app(TransferRepository::class);

        $transferRepo->storeTransfer([
            'from_warehouse_id' => $f['warehouse']->id,
            'to_warehouse_id' => $f['warehouse2']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => 1,
            'transfer_items' => [[
                'product_id' => $f['product']->id,
                'product_price' => 25,
                'tax_type' => 1,
                'tax_value' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'quantity' => 4,
            ]],
        ]);

        $this->assertEquals(6.0, (float) ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->value('quantity'));
        $this->assertEquals(4.0, (float) ManageStock::whereWarehouseId($f['warehouse2']->id)->whereProductId($f['product']->id)->value('quantity'));

        $this->expectException(UnprocessableEntityHttpException::class);
        $transferRepo->storeTransfer([
            'from_warehouse_id' => $f['warehouse']->id,
            'to_warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => 1,
            'transfer_items' => [[
                'product_id' => $f['product']->id,
                'product_price' => 25,
                'tax_type' => 1,
                'tax_value' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'quantity' => 1,
            ]],
        ]);
    }

    public function test_stock_valuation_and_tenant_isolation_on_manage_stocks(): void
    {
        [$userA, , $tenantA] = $this->createTenantWithUser(Role::ADMIN, ['manage_reports']);
        [$userB, , $tenantB] = $this->createTenantWithUser(Role::ADMIN, ['manage_reports']);

        $this->actingAs($userA, 'sanctum');
        $fA = $this->createInventoryFixtures($tenantA->id);
        ManageStock::create(['warehouse_id' => $fA['warehouse']->id, 'product_id' => $fA['product']->id, 'quantity' => 10]);

        $this->actingAs($userB, 'sanctum');
        $fB = $this->createInventoryFixtures($tenantB->id);
        ManageStock::create(['warehouse_id' => $fB['warehouse']->id, 'product_id' => $fB['product']->id, 'quantity' => 50]);

        // Tenant A should only see its own 10 units (cost=100, retail=250)
        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/stock-valuation');
        $response->assertStatus(200)
            ->assertJsonPath('data.total_quantity', 10)
            ->assertJsonPath('data.total_cost_valuation', 100)
            ->assertJsonPath('data.total_retail_valuation', 250);
    }

    public function test_sales_return_restores_stock_and_purchase_return_deducts_stock(): void
    {
        [$user, , $tenant] = $this->createTenantWithUser();
        $this->actingAs($user, 'sanctum');
        $f = $this->createInventoryFixtures($tenant->id);

        /** @var PurchaseRepository $purchaseRepo */
        $purchaseRepo = app(PurchaseRepository::class);
        $purchase = $purchaseRepo->storePurchase([
            'supplier_id' => $f['supplier']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => Purchase::RECEIVED,
            'payment_status' => Purchase::PAID,
            'purchase_items' => [[
                'product_id' => $f['product']->id,
                'product_cost' => 10,
                'tax_type' => Purchase::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Purchase::FIXED,
                'discount_value' => 0,
                'purchase_unit' => $f['unit']->id,
                'quantity' => 10,
            ]],
        ]);
        $this->assertEquals(10.0, (float) ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->value('quantity'));

        /** @var SaleRepository $saleRepo */
        $saleRepo = app(SaleRepository::class);
        $sale = $saleRepo->storeSale([
            'customer_id' => $f['customer']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => Sale::COMPLETED,
            'payment_status' => Sale::PAID,
            'sale_items' => [[
                'product_id' => $f['product']->id,
                'product_price' => 25,
                'tax_type' => Sale::EXCLUSIVE,
                'tax_value' => 0,
                'discount_type' => Sale::FIXED,
                'discount_value' => 0,
                'sale_unit' => $f['unit']->id,
                'quantity' => 4,
            ]],
            'payment_details' => [[
                'amount' => 100,
                'payment_type' => ['value' => $f['cashMethod']->id],
            ]],
        ]);
        $this->assertEquals(6.0, (float) ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->value('quantity'));

        /** @var SaleReturnRepository $saleReturnRepo */
        $saleReturnRepo = app(SaleReturnRepository::class);
        $saleReturnRepo->storeSaleReturn([
            'sale_id' => $sale->id,
            'customer_id' => $f['customer']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => 1,
            'sale_return_items' => [[
                'product_id' => $f['product']->id,
                'product_price' => 25,
                'tax_type' => 1,
                'tax_value' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'sale_unit' => $f['unit']->id,
                'quantity' => 2,
                'sold_quantity' => 4,
            ]],
        ]);
        // Stock restored 6 -> 8
        $this->assertEquals(8.0, (float) ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->value('quantity'));

        /** @var PurchaseReturnRepository $purchaseReturnRepo */
        $purchaseReturnRepo = app(PurchaseReturnRepository::class);
        $purchaseReturnRepo->storePurchaseReturn([
            'purchase_id' => $purchase->id,
            'supplier_id' => $f['supplier']->id,
            'warehouse_id' => $f['warehouse']->id,
            'date' => now()->format('Y-m-d'),
            'tax_rate' => 0,
            'discount' => 0,
            'shipping' => 0,
            'status' => 1,
            'payment_status' => 1,
            'purchase_return_items' => [[
                'product_id' => $f['product']->id,
                'product_cost' => 10,
                'tax_type' => 1,
                'tax_value' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'purchase_unit' => $f['unit']->id,
                'quantity' => 3,
            ]],
        ]);
        // Stock deducted 8 -> 5
        $this->assertEquals(5.0, (float) ManageStock::whereWarehouseId($f['warehouse']->id)->whereProductId($f['product']->id)->value('quantity'));
    }

    public function test_secure_file_controller_enforces_auth_permissions_tenant_isolation_and_anti_traversal(): void
    {
        [$userA, , $tenantA] = $this->createTenantWithUser(Role::ADMIN, ['manage_reports']);
        [$userB, , $tenantB] = $this->createTenantWithUser(Role::ADMIN, ['manage_reports']);
        [$noPermUser] = $this->createTenantWithUser('restricted_' . uniqid(), []);

        $disk = \Illuminate\Support\Facades\Storage::disk('local_protected');
        $fileA = $tenantA->id . '/invoice-test.pdf';
        $fileB = $tenantB->id . '/secret-b.pdf';
        $badExt = $tenantA->id . '/shell.php';

        $disk->put($fileA, '%PDF-1.4 test');
        $disk->put($fileB, '%PDF-1.4 secret');
        $disk->put($badExt, '<?php echo 1;');

        try {
            // 1. Unauthenticated -> 401
            $this->getJson('/api/private-files/' . $fileA)->assertStatus(401);

            // 2. Authenticated without permission -> 403
            $this->actingAs($noPermUser, 'sanctum')
                ->getJson('/api/private-files/' . $fileA)
                ->assertStatus(403);

            // 3. Cross-tenant access -> 403
            $this->actingAs($userA, 'sanctum')
                ->getJson('/api/private-files/' . $fileB)
                ->assertStatus(403);

            // 4. Encoded path traversal -> 403
            $this->actingAs($userA, 'sanctum')
                ->getJson('/api/private-files/' . $tenantA->id . '/%2e%2e/' . $tenantB->id . '/secret-b.pdf')
                ->assertStatus(403);

            // 5. Double-encoded path traversal -> 403
            $this->actingAs($userA, 'sanctum')
                ->getJson('/api/private-files/' . $tenantA->id . '/%252e%252e/' . $tenantB->id . '/secret-b.pdf')
                ->assertStatus(403);

            // 6. Disallowed extension (.php) -> 403
            $this->actingAs($userA, 'sanctum')
                ->getJson('/api/private-files/' . $badExt)
                ->assertStatus(403);

            // 7. Authorized same-tenant PDF download -> 200 with nosniff header
            $this->actingAs($userA, 'sanctum')
                ->get('/api/private-files/' . $fileA)
                ->assertStatus(200)
                ->assertHeader('X-Content-Type-Options', 'nosniff');
        } finally {
            $disk->delete([$fileA, $fileB, $badExt]);
        }
    }

    public function test_stockmate_admin_accounts_authenticate_and_reject_invalid_credentials(): void
    {
        // Super Admin normal login
        $saRes = $this->postJson('/api/login', [
            'email' => 'superadmin@stockmate.com',
            'password' => 'Stockmate@1234',
        ]);
        $saRes->assertStatus(200)
            ->assertJsonPath('data.roles', Role::SUPER_ADMIN)
            ->assertJsonPath('data.user.email', 'superadmin@stockmate.com');

        // Store Admin normal login
        $adRes = $this->postJson('/api/login', [
            'email' => 'admin@stockmate.com',
            'password' => 'Stockmate@1234',
        ]);
        $adRes->assertStatus(200)
            ->assertJsonPath('data.roles', Role::ADMIN)
            ->assertJsonPath('data.user.email', 'admin@stockmate.com');
        $this->assertNotEmpty($adRes->json('data.user.tenant_id'));

        // Invalid credentials rejected
        $this->postJson('/api/login', [
            'email' => 'admin@stockmate.com',
            'password' => 'WrongPassword!',
        ])->assertStatus(422);
    }

    public function test_quick_login_is_disabled_and_unregistered_outside_local_environment(): void
    {
        // PHPUnit runs with APP_ENV=testing (non-local)
        $this->assertFalse(app()->environment('local'));

        // Capability flag must be false outside local
        $this->getJson('/api/front-cms')
            ->assertStatus(200)
            ->assertJsonPath('data.value.local_quick_login_enabled', false);

        // Route must not be registered at all outside local
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('local.quick-login'));

        // Direct HTTP request outside local must return 404
        $this->post('/local-quick-login', ['role' => 'superadmin'])
            ->assertStatus(404);

        // Controller guard itself must return 404 outside local
        $controller = app(\App\Http\Controllers\API\AuthController::class);
        $directResponse = $controller->quickLogin(\Illuminate\Http\Request::create('/local-quick-login', 'POST', ['role' => 'superadmin']));
        $this->assertEquals(404, $directResponse->getStatusCode());
    }

    public function test_logout_revokes_bearer_token_and_blocks_subsequent_protected_requests(): void
    {
        $loginRes = $this->postJson('/api/login', [
            'email' => 'admin@stockmate.com',
            'password' => 'Stockmate@1234',
        ]);
        $loginRes->assertStatus(200);
        $token = $loginRes->json('data.token');
        $this->assertNotEmpty($token);

        // Protected endpoint succeeds before logout
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/today-sales-purchases-count')
            ->assertStatus(200);

        // Logout revokes token
        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout')
            ->assertStatus(200);

        // Token record must be deleted from personal_access_tokens
        $this->assertNull(\Laravel\Sanctum\PersonalAccessToken::findToken($token));
    }

    public function test_stockmate_contact_information_and_whatsapp_links_render_on_landing_page(): void
    {
        $response = $this->get('/');
        $response->assertStatus(200)
            ->assertSee('01749-560393')
            ->assertSee('01931-336664')
            ->assertSee('dynamic.digital.ltd@gmail.com')
            ->assertSee('Mirpur, Kushtia, Bangladesh - 7030')
            ->assertSee('https://wa.me/8801749560393', false)
            ->assertSee('https://wa.me/8801931336664', false)
            ->assertSee('mailto:dynamic.digital.ltd@gmail.com', false)
            ->assertDontSee('+91 70963 36561')
            ->assertDontSee('Atlanta Shopping Mall');
    }

    public function test_usd_is_default_currency_and_bdt_is_available_without_duplicates(): void
    {
        $usd = \App\Models\Currency::where('code', 'USD')->get();
        $bdt = \App\Models\Currency::where('code', 'BDT')->get();

        $this->assertCount(1, $usd);
        $this->assertEquals('$', $usd->first()->symbol);
        $this->assertEquals('United States', $usd->first()->name);

        $this->assertCount(1, $bdt);
        $this->assertEquals('৳', $bdt->first()->symbol);
        $this->assertEquals('Bangladesh', $bdt->first()->name);

        // System default currency must be USD
        $this->assertEquals((string) $usd->first()->id, (string) getSadminSettingValue('admin_default_currency'));
        $this->assertEquals('$', getSadminSettingValue('admin_default_currency_symbol'));

        // Tenant store currency must be configured to a valid currency (USD default or BDT for Bangladesh grocery demo stores)
        $tenantCurrencyId = (string) \Illuminate\Support\Facades\DB::table('settings')->where('key', 'currency')->value('value');
        $this->assertContains($tenantCurrencyId, [(string) $usd->first()->id, (string) $bdt->first()->id]);
    }
}
