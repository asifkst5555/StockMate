<?php

namespace Tests\Feature;

use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\MultiTenant;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
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
use Tests\TestCase;

class CustomSearchFilterIsolationTest extends TestCase
{
    private string $token;

    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $staffA = null;
    private ?User $adminB = null;
    private ?User $staffB = null;

    private ?Store $storeA = null;
    private ?Store $storeB = null;

    private ?Role $roleA = null;
    private ?Role $roleB = null;

    private ?Warehouse $whA1 = null;
    private ?Warehouse $whA2 = null;
    private ?Warehouse $whB1 = null;

    private ?Product $productA1 = null;
    private ?Product $productA2 = null;
    private ?Product $productB1 = null;

    private array $cleanupMainProductIds = [];
    private array $cleanupBrandIds = [];
    private array $cleanupCategoryIds = [];
    private array $cleanupUnitIds = [];
    private array $cleanupBaseUnitIds = [];
    private array $cleanupSubscriptionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'test_tA_' . $this->token;
        $this->tenantBId = 'test_tB_' . $this->token;

        $plan = Plan::first();

        // Insert tenants directly to avoid triggering Stancl multi-DB creation
        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'store_id' => "store_alpha_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
            [
                'id' => $this->tenantBId,
                'store_id' => "store_beta_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
        ]);

        // Tenant A Admin & Staff
        $this->adminA = $this->createUser("AlphaAdmin_{$this->token}", 'Owner', "alpha.admin.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->staffA = $this->createUser("AlphaStaff_{$this->token}", 'Cashier', "alpha.staff.{$this->token}@stockmate.test", $this->tenantAId, null);

        // Tenant B Admin & Staff
        $this->adminB = $this->createUser("BetaAdmin_{$this->token}", 'Owner', "beta.admin.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);
        $this->staffB = $this->createUser("BetaStaff_{$this->token}", 'Cashier', "beta.staff.{$this->token}@stockmate.test", $this->tenantBId, null);

        // Stores & UserStore assignments
        $this->storeA = Store::create([
            'name' => "Alpha Store {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);
        $this->storeB = Store::create([
            'name' => "Beta Store {$this->token}",
            'tenant_id' => $this->tenantBId,
            'user_id' => $this->adminB->id,
            'status' => 1,
        ]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->staffA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);
        UserStore::create(['user_id' => $this->staffB->id, 'store_id' => $this->storeB->id]);

        // Active subscriptions for both Store Admins so check_subscription passes
        if ($plan) {
            foreach ([$this->adminA, $this->adminB] as $owner) {
                $sub = Subscription::create([
                    'user_id' => $owner->id,
                    'plan_id' => $plan->id,
                    'plan_amount' => $plan->price ?? 0,
                    'plan_frequency' => $plan->frequency ?? 1,
                    'start_date' => Carbon::now()->subDay(),
                    'end_date' => Carbon::now()->addMonth(),
                    'status' => Subscription::ACTIVE,
                ]);
                $this->cleanupSubscriptionIds[] = $sub->id;
            }
        }

        // Custom Roles in Tenant A and Tenant B
        $this->roleA = Role::withoutGlobalScope('tenant')->create([
            'name' => "AlphaRole_{$this->token}",
            'display_name' => "Alpha Role {$this->token}",
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        $this->roleB = Role::withoutGlobalScope('tenant')->create([
            'name' => "BetaRole_{$this->token}",
            'display_name' => "Beta Role {$this->token}",
            'guard_name' => 'web',
            'tenant_id' => $this->tenantBId,
        ]);

        // Warehouses & Products for Tenant A (2 warehouses) and Tenant B (1 warehouse)
        $this->whA1 = $this->createWarehouse("WH_A1_{$this->token}", $this->tenantAId);
        $this->whA2 = $this->createWarehouse("WH_A2_{$this->token}", $this->tenantAId);
        $this->whB1 = $this->createWarehouse("WH_B1_{$this->token}", $this->tenantBId);

        $this->productA1 = $this->createProduct("SharedItem_{$this->token}_A1", "CODE_A1_{$this->token}", $this->tenantAId, $this->whA1->id);
        $this->productA2 = $this->createProduct("SharedItem_{$this->token}_A2", "CODE_A2_{$this->token}", $this->tenantAId, $this->whA2->id);
        $this->productB1 = $this->createProduct("SharedItem_{$this->token}_B1", "CODE_B1_{$this->token}", $this->tenantBId, $this->whB1->id);
    }

    protected function tearDown(): void
    {
        $productIds = array_filter([$this->productA1?->id, $this->productA2?->id, $this->productB1?->id]);
        if (! empty($productIds)) {
            ManageStock::withoutGlobalScope('tenant')->whereIn('product_id', $productIds)->delete();
            Product::withoutGlobalScope('tenant')->whereIn('id', $productIds)->forceDelete();
        }
        if (! empty($this->cleanupMainProductIds)) {
            MainProduct::withoutGlobalScope('tenant')->whereIn('id', $this->cleanupMainProductIds)->forceDelete();
        }
        $whIds = array_filter([$this->whA1?->id, $this->whA2?->id, $this->whB1?->id]);
        if (! empty($whIds)) {
            Warehouse::withoutGlobalScope('tenant')->whereIn('id', $whIds)->forceDelete();
        }
        if (! empty($this->cleanupUnitIds)) {
            Unit::withoutGlobalScope('tenant')->whereIn('id', $this->cleanupUnitIds)->forceDelete();
        }
        if (! empty($this->cleanupBaseUnitIds)) {
            BaseUnit::withoutGlobalScope('tenant')->whereIn('id', $this->cleanupBaseUnitIds)->forceDelete();
        }
        if (! empty($this->cleanupBrandIds)) {
            Brand::withoutGlobalScope('tenant')->whereIn('id', $this->cleanupBrandIds)->forceDelete();
        }
        if (! empty($this->cleanupCategoryIds)) {
            ProductCategory::withoutGlobalScope('tenant')->whereIn('id', $this->cleanupCategoryIds)->forceDelete();
        }
        $roleIds = array_filter([$this->roleA?->id, $this->roleB?->id]);
        if (! empty($roleIds)) {
            Role::withoutGlobalScope('tenant')->whereIn('id', $roleIds)->delete();
        }
        if (! empty($this->cleanupSubscriptionIds)) {
            Subscription::whereIn('id', $this->cleanupSubscriptionIds)->delete();
        }
        $userIds = array_filter([$this->adminA?->id, $this->staffA?->id, $this->adminB?->id, $this->staffB?->id]);
        if (! empty($userIds)) {
            UserStore::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->where('model_type', User::class)->delete();
            User::withoutGlobalScope('tenant')->whereIn('id', $userIds)->forceDelete();
        }
        $storeIds = array_filter([$this->storeA?->id, $this->storeB?->id]);
        if (! empty($storeIds)) {
            Store::whereIn('id', $storeIds)->delete();
        }
        DB::table('tenants')->whereIn('id', [$this->tenantAId, $this->tenantBId])->delete();

        parent::tearDown();
    }

    private function createUser(string $firstName, string $lastName, string $email, string $tenantId, ?string $role): User
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => '92301' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function createWarehouse(string $name, string $tenantId): Warehouse
    {
        return Warehouse::withoutGlobalScope('tenant')->create([
            'name' => $name,
            'phone' => '92302' . random_int(1000000, 9999999),
            'country' => 'PK',
            'city' => 'Lahore',
            'email' => Str::lower($name) . '@stockmate.test',
            'zip_code' => '54000',
            'tenant_id' => $tenantId,
        ]);
    }

    private function createProduct(string $name, string $code, string $tenantId, int $warehouseId): Product
    {
        $brand = Brand::withoutGlobalScope('tenant')->create([
            'name' => "Brand_{$code}",
            'tenant_id' => $tenantId,
        ]);
        $this->cleanupBrandIds[] = $brand->id;

        $category = ProductCategory::withoutGlobalScope('tenant')->create([
            'name' => "Cat_{$code}",
            'tenant_id' => $tenantId,
        ]);
        $this->cleanupCategoryIds[] = $category->id;

        $baseUnit = BaseUnit::withoutGlobalScope('tenant')->create([
            'name' => "Pcs_{$code}",
            'tenant_id' => $tenantId,
        ]);
        $this->cleanupBaseUnitIds[] = $baseUnit->id;

        $unit = Unit::withoutGlobalScope('tenant')->create([
            'name' => "Piece_{$code}",
            'short_name' => 'pc',
            'base_unit' => $baseUnit->id,
            'tenant_id' => $tenantId,
        ]);
        $this->cleanupUnitIds[] = $unit->id;

        $mainProduct = MainProduct::withoutGlobalScope('tenant')->create([
            'name' => $name,
            'code' => $code,
            'product_unit' => $baseUnit->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
            'tenant_id' => $tenantId,
        ]);
        $this->cleanupMainProductIds[] = $mainProduct->id;

        $product = Product::withoutGlobalScope('tenant')->create([
            'name' => $name,
            'code' => $code,
            'product_code' => $code,
            'main_product_id' => $mainProduct->id,
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_cost' => 10,
            'product_price' => 20,
            'product_unit' => $baseUnit->id,
            'sale_unit' => $unit->id,
            'purchase_unit' => $unit->id,
            'barcode_symbol' => Product::CODE128,
            'tenant_id' => $tenantId,
        ]);

        $stock = new ManageStock([
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'quantity' => 50,
        ]);
        $stock->tenant_id = $tenantId;
        $stock->save();

        return $product;
    }

    public function test_users_search_enforces_tenant_isolation_and_blocks_manipulated_search_fields(): void
    {
        // 1. Normal search finds own tenant staff
        $normalResp = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/users?filter[search]=AlphaStaff_{$this->token}");
        $normalResp->assertOk();
        $ids = collect($normalResp->json('data'))->pluck('id')->all();
        $this->assertContains($this->staffA->id, $ids);
        $this->assertNotContains($this->staffB->id, $ids);
        $this->assertNotContains($this->adminB->id, $ids);

        // 2. Broad search (@) must NOT leak Tenant B users or Super Admin
        $broadResp = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/users?filter[search]=@');
        $broadResp->assertOk();
        $broadEmails = collect($broadResp->json('data'))->pluck('attributes.email')->all();
        $this->assertNotContains($this->staffB->email, $broadEmails);
        $this->assertNotContains($this->adminB->email, $broadEmails);
        $this->assertNotContains('superadmin@stockmate.com', $broadEmails);

        // 3. Direct search for Tenant B's staff returns empty
        $crossResp = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/users?filter[search]=BetaStaff_{$this->token}");
        $crossResp->assertOk();
        $this->assertEmpty($crossResp->json('data'));

        // 4. Manipulated filter[search_fields]=password or tenant_id is blocked (0 results, no probing)
        $passwordProbe = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/users?filter[search]=$2y$&filter[search_fields]=password');
        $passwordProbe->assertOk();
        $this->assertEmpty($passwordProbe->json('data'));

        $tenantProbe = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/users?filter[search]={$this->tenantBId}&filter[search_fields]=tenant_id");
        $tenantProbe->assertOk();
        $this->assertEmpty($tenantProbe->json('data'));
    }

    public function test_users_search_supports_allowlisted_search_fields_subset(): void
    {
        $subsetResp = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/users?filter[search]=AlphaStaff_{$this->token}&filter[search_fields]=first_name");
        $subsetResp->assertOk();
        $this->assertContains($this->staffA->id, collect($subsetResp->json('data'))->pluck('id')->all());
    }

    public function test_roles_search_enforces_tenant_and_global_role_exclusions(): void
    {
        // 1. Normal search finds Tenant A custom role
        $normalResp = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/roles?filter[search]=AlphaRole_{$this->token}");
        $normalResp->assertOk();
        $names = collect($normalResp->json('data'))->pluck('attributes.name')->all();
        $this->assertContains($this->roleA->name, $names);
        $this->assertNotContains($this->roleB->name, $names);

        // 2. Searching 'admin' must NOT expose global superadmin or admin roles
        $adminSearch = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/roles?filter[search]=admin');
        $adminSearch->assertOk();
        $adminNames = collect($adminSearch->json('data'))->pluck('attributes.name')->all();
        $this->assertNotContains(Role::SUPER_ADMIN, $adminNames);
        $this->assertNotContains(Role::ADMIN, $adminNames);

        // 3. Searching Tenant B role returns empty
        $crossRole = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/roles?filter[search]=BetaRole_{$this->token}");
        $crossRole->assertOk();
        $this->assertEmpty($crossRole->json('data'));

        // 4. Manipulated filter[search_fields]=guard_name returns empty
        $guardProbe = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/roles?filter[search]=web&filter[search_fields]=guard_name');
        $guardProbe->assertOk();
        $this->assertEmpty($guardProbe->json('data'));
    }

    public function test_products_search_preserves_tenant_and_warehouse_filters(): void
    {
        // 1. Search with shared prefix returns ONLY Tenant A products, never Tenant B
        $tenantSearch = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/products?filter[search]=SharedItem_{$this->token}");
        $tenantSearch->assertOk();
        $ids = collect($tenantSearch->json('data'))->pluck('id')->all();
        $this->assertContains($this->productA1->id, $ids);
        $this->assertContains($this->productA2->id, $ids);
        $this->assertNotContains($this->productB1->id, $ids);

        // 2. Search WITH warehouse_id=whA1 must preserve warehouse filter (excludes whA2 & Tenant B!)
        $whSearch = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/products?warehouse_id={$this->whA1->id}&filter[search]=SharedItem_{$this->token}");
        $whSearch->assertOk();
        $whIds = collect($whSearch->json('data'))->pluck('id')->all();
        $this->assertContains($this->productA1->id, $whIds);
        $this->assertNotContains($this->productA2->id, $whIds);
        $this->assertNotContains($this->productB1->id, $whIds);

        // 3. Manipulated filter[search_fields]=tenant_id returns 0 records
        $fieldProbe = $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/products?filter[search]={$this->tenantBId}&filter[search_fields]=tenant_id");
        $fieldProbe->assertOk();
        $this->assertEmpty($fieldProbe->json('data'));
    }

    public function test_super_admin_search_works_as_intended_and_blocks_disallowed_fields(): void
    {
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->first();
        $this->assertNotNull($superAdmin, 'Super Admin account must exist');

        $resp = $this->actingAs($superAdmin, 'sanctum')
            ->getJson("/api/sadmin/admin-users?filter[search]=AlphaAdmin_{$this->token}");
        $resp->assertOk();
        $ids = collect($resp->json('data'))->pluck('id')->all();
        $this->assertContains($this->adminA->id, $ids);

        // Disallowed column probe still blocked even for Super Admin
        $probe = $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/sadmin/admin-users?filter[search]=$2y$&filter[search_fields]=password');
        $probe->assertOk();
        $this->assertEmpty($probe->json('data'));
    }
}
