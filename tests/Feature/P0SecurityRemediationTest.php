<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\ExpenseCategory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class P0SecurityRemediationTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantA2Id;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $subUserA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Store $storeADisabled = null;
    private ?Store $storeB = null;
    private ?Plan $plan = null;
    private ?Warehouse $warehouseA = null;
    private ?Warehouse $warehouseB = null;
    private ?Customer $customerA = null;
    private ?Customer $customerB = null;
    private ?Supplier $supplierB = null;
    private ?Brand $brandB = null;
    private ?ProductCategory $categoryB = null;
    private ?ExpenseCategory $expenseCatA = null;
    private ?ExpenseCategory $expenseCatB = null;
    private ?Product $productB = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'p0_tA_' . $this->token;
        $this->tenantA2Id = 'p0_tA2_' . $this->token;
        $this->tenantBId = 'p0_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "p0_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantA2Id, 'store_id' => "p0_sA2_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "p0_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("P0AdminA_{$this->token}", "p0.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->subUserA = $this->makeUser("P0SubA_{$this->token}", "p0.suba.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("P0AdminB_{$this->token}", "p0.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);

        $this->storeA = Store::create(['name' => "P0 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeADisabled = Store::create(['name' => "P0 Store A2 {$this->token}", 'tenant_id' => $this->tenantA2Id, 'user_id' => $this->adminA->id, 'status' => 0]);
        $this->storeB = Store::create(['name' => "P0 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeADisabled->id]);
        UserStore::create(['user_id' => $this->subUserA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "P0 Plan {$this->token}",
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
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'status' => Subscription::ACTIVE,
            ]);
        }

        $this->warehouseA = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $this->tenantAId, 'name' => "WH A {$this->token}", 'phone' => '111', 'country' => 'US', 'city' => 'NY', 'email' => "wha.{$this->token}@test.local"]);
        $this->warehouseB = Warehouse::withoutGlobalScopes()->create(['tenant_id' => $this->tenantBId, 'name' => "WH B {$this->token}", 'phone' => '222', 'country' => 'US', 'city' => 'LA', 'email' => "whb.{$this->token}@test.local"]);

        $this->customerA = Customer::withoutGlobalScopes()->create(['tenant_id' => $this->tenantAId, 'name' => "Cust A {$this->token}", 'email' => "ca.{$this->token}@test.local", 'phone' => '111', 'country' => 'US', 'city' => 'NY', 'address' => 'A']);
        $this->customerB = Customer::withoutGlobalScopes()->create(['tenant_id' => $this->tenantBId, 'name' => "Cust B {$this->token}", 'email' => "cb.{$this->token}@test.local", 'phone' => '222', 'country' => 'US', 'city' => 'LA', 'address' => 'B']);

        $this->supplierB = Supplier::withoutGlobalScopes()->create(['tenant_id' => $this->tenantBId, 'name' => "Sup B {$this->token}", 'email' => "sb.{$this->token}@test.local", 'phone' => '222', 'country' => 'US', 'city' => 'LA', 'address' => 'B']);
        $this->brandB = Brand::withoutGlobalScopes()->create(['tenant_id' => $this->tenantBId, 'name' => "Brand B {$this->token}"]);
        $this->categoryB = ProductCategory::withoutGlobalScopes()->create(['tenant_id' => $this->tenantBId, 'name' => "Cat B {$this->token}"]);
        $this->expenseCatA = ExpenseCategory::withoutGlobalScopes()->create(['tenant_id' => $this->tenantAId, 'name' => "ExpCat A {$this->token}"]);
        $this->expenseCatB = ExpenseCategory::withoutGlobalScopes()->create(['tenant_id' => $this->tenantBId, 'name' => "ExpCat B {$this->token}"]);

        $this->productB = Product::withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantBId,
            'name' => "Prod B {$this->token}",
            'code' => "PB{$this->token}",
            'product_category_id' => $this->categoryB->id,
            'brand_id' => $this->brandB->id,
            'product_cost' => 10,
            'product_price' => 20,
            'product_unit' => '1',
            'sale_unit' => '1',
            'purchase_unit' => '1',
            'barcode_symbol' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $tenantIds = [$this->tenantAId, $this->tenantA2Id, $this->tenantBId];
        DB::table('expenses')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('expense_categories')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('products')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('product_categories')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('brands')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('customers')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('suppliers')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('warehouses')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('settings')->whereIn('tenant_id', $tenantIds)->delete();

        $userIds = array_filter([$this->adminA?->id, $this->subUserA?->id, $this->adminB?->id]);
        if (! empty($userIds)) {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $userIds)->where('tokenable_type', User::class)->delete();
            Subscription::whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
            User::withoutGlobalScope('tenant')->whereIn('id', $userIds)->forceDelete();
        }

        $storeIds = array_filter([$this->storeA?->id, $this->storeADisabled?->id, $this->storeB?->id]);
        if (! empty($storeIds)) {
            Store::whereIn('id', $storeIds)->delete();
        }
        if ($this->plan) {
            Plan::where('id', $this->plan->id)->delete();
        }
        DB::table('tenants')->whereIn('id', $tenantIds)->delete();

        parent::tearDown();
    }

    private function makeUser(string $name, string $email, string $tenantId, string $role): User
    {
        $u = User::withoutGlobalScope('tenant')->create([
            'first_name' => $name,
            'last_name' => 'User',
            'email' => $email,
            'phone' => '92312' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $u->assignRole($role);

        return $u;
    }

    // -------------------------------------------------------------------------
    // P0-A: Upload security & SVG XSS blocking
    // -------------------------------------------------------------------------

    public function test_p0a_blocks_svg_xss_and_malicious_uploads_across_settings_brands_and_imports(): void
    {
        $svgPayload = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        $svgFile = UploadedFile::fake()->createWithContent('evil.svg', $svgPayload);
        $phpFile = UploadedFile::fake()->createWithContent('shell.php', '<?php system($_GET["c"]); ?>');
        $fakePngWithPhp = UploadedFile::fake()->createWithContent('fake.png', '<?php echo 1; ?>');

        // 1. Brand rejects SVG
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/brands', ['name' => "SVG Brand {$this->token}", 'image' => $svgFile], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // 2. Settings store_logo rejects SVG and disguised PHP
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/settings', ['store_logo' => $svgFile], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/settings', ['store_logo' => $fakePngWithPhp], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // 3. Dual screen rejects SVG
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/dual-screen-settings/update', ['image1' => $svgFile], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // 4. Import endpoints reject PHP executable uploads
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/import-products', ['file' => $phpFile], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/import-customers', ['file' => $phpFile], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/import-suppliers', ['file' => $phpFile], ['Accept' => 'application/json'])
            ->assertStatus(422);

        // 5. Legitimate PNG upload succeeds on Brand
        $validPng = UploadedFile::fake()->image('brand.png', 100, 100);
        $this->actingAs($this->adminA, 'sanctum')
            ->post('/api/brands', ['name' => "Valid Brand {$this->token}", 'image' => $validPng], ['Accept' => 'application/json'])
            ->assertStatus(201);
    }

    // -------------------------------------------------------------------------
    // P0-B: Public export tenant isolation via private protected storage
    // -------------------------------------------------------------------------

    public function test_p0b_exports_are_stored_privately_and_enforced_per_tenant(): void
    {
        $exportResp = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products-export-excel')
            ->assertStatus(200);

        $url = (string) $exportResp->json('data.product_excel_url');
        $this->assertStringContainsString('/api/private-files/' . $this->tenantAId . '/exports/excel/', $url);
        $this->assertStringNotContainsString('/uploads/', $url);

        $relativePath = Str::after($url, '/api/private-files/');
        $disk = Storage::disk(config('filesystems.protected', 'local_protected'));
        $this->assertTrue($disk->exists($relativePath));

        // Tenant A downloads own export -> 200 with nosniff header
        $this->actingAs($this->adminA, 'sanctum')
            ->get('/api/private-files/' . $relativePath)
            ->assertStatus(200)
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        // Tenant B cannot download Tenant A's export -> 403
        $this->actingAs($this->adminB, 'sanctum')
            ->get('/api/private-files/' . $relativePath)
            ->assertStatus(403);

        // Unauthenticated cannot download -> 401
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/private-files/' . $relativePath)->assertStatus(401);

        // Cleanup generated private test file
        $disk->delete($relativePath);
    }

    // -------------------------------------------------------------------------
    // P0-C: Cross-tenant foreign-key exists validation
    // -------------------------------------------------------------------------

    public function test_p0c_blocks_cross_tenant_foreign_key_ids_on_sales_expenses_products_and_coupons(): void
    {
        // 1. Sale with Tenant B's customer_id or warehouse_id -> 422
        $respSaleCust = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', [
                'date' => now()->toDateString(),
                'customer_id' => $this->customerB->id,
                'warehouse_id' => $this->warehouseA->id,
                'status' => 1,
                'payment_status' => 2,
                'grand_total' => 100,
                'sale_items' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertStringContainsString('customer_id', strtolower((string) $respSaleCust->json('message')));

        $respSaleWh = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/sales', [
                'date' => now()->toDateString(),
                'customer_id' => $this->customerA->id,
                'warehouse_id' => $this->warehouseB->id,
                'status' => 1,
                'payment_status' => 2,
                'grand_total' => 100,
                'sale_items' => [],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertStringContainsString('warehouse_id', strtolower((string) $respSaleWh->json('message')));

        // 2. Expense with Tenant B's warehouse_id & expense_category_id -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/expenses', [
                'date' => now()->toDateString(),
                'warehouse_id' => $this->warehouseB->id,
                'expense_category_id' => $this->expenseCatB->id,
                'amount' => 50,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // 3. Same-tenant Expense succeeds -> 201
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/expenses', [
                'date' => now()->toDateString(),
                'title' => 'Office Supplies',
                'warehouse_id' => $this->warehouseA->id,
                'expense_category_id' => $this->expenseCatA->id,
                'amount' => 50,
            ])
            ->assertStatus(201);

        // 4. Coupon referencing Tenant B's product -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/coupon-codes', [
                'name' => "Bad Coupon {$this->token}",
                'code' => "BAD{$this->token}",
                'start_date' => now()->toDateString(),
                'end_date' => now()->addWeek()->toDateString(),
                'how_many_time_can_use' => 5,
                'discount_type' => 1,
                'discount' => 10,
                'products' => [$this->productB->id],
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // -------------------------------------------------------------------------
    // P0-D: Store switching authorization (cross-tenant + disabled store)
    // -------------------------------------------------------------------------

    public function test_p0d_store_switching_blocks_cross_tenant_and_disabled_stores(): void
    {
        // 1. Tenant A cannot switch to Tenant B's store -> 403
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/change-store/{$this->storeB->id}")
            ->assertStatus(403);
        $this->assertSame($this->tenantAId, $this->adminA->fresh()->tenant_id);

        // 2. Tenant A cannot switch to its own disabled store (status = 0) -> 403
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/change-store/{$this->storeADisabled->id}")
            ->assertStatus(403);
        $this->assertSame($this->tenantAId, $this->adminA->fresh()->tenant_id);

        // 3. Once enabled, Tenant A can switch to its own branch store -> 200
        $this->storeADisabled->update(['status' => 1]);
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/change-store/{$this->storeADisabled->id}")
            ->assertStatus(200);
        $this->assertSame($this->tenantA2Id, $this->adminA->fresh()->tenant_id);
    }

    // -------------------------------------------------------------------------
    // P0-E: Suspended sub-user & suspended tenant-owner access enforcement
    // -------------------------------------------------------------------------

    public function test_p0e_suspended_owner_and_disabled_store_block_sub_user_and_revoke_token(): void
    {
        $subToken = $this->subUserA->createToken('sub-session')->plainTextToken;

        // 1. Active sub-user with active owner & active store -> 200
        $this->withHeader('Authorization', 'Bearer ' . $subToken)
            ->getJson('/api/products')
            ->assertStatus(200);

        // 2. Disable active store -> sub-user blocked (403)
        $this->storeA->update(['status' => 0]);
        $this->withHeader('Authorization', 'Bearer ' . $subToken)
            ->getJson('/api/products')
            ->assertStatus(403);

        // Re-enable store, then suspend owning Store Admin -> sub-user immediately blocked (401) & token revoked!
        $this->storeA->update(['status' => 1]);
        $this->adminA->update(['status' => 0]);

        $this->withHeader('Authorization', 'Bearer ' . $subToken)
            ->getJson('/api/products')
            ->assertStatus(401);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $this->subUserA->id,
        ]);

        // 3. Super Admin remains completely unaffected -> 200
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->firstOrFail();
        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/sadmin/admin-users')
            ->assertStatus(200);
    }
}
