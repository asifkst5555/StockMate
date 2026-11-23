<?php

namespace Tests\Feature;

use App\Models\MultiTenant;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use App\Repositories\AdminRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class FinalSecurityHardeningTest extends TestCase
{
    use DatabaseTransactions;

    private function createTenantAdmin(string $prefix): array
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $prefix,
            'last_name' => 'Admin',
            'email' => $prefix . '_' . uniqid() . '@example.com',
            'phone' => (string) random_int(1000000000, 9999999999),
            'password' => Hash::make('Password@123'),
            'email_verified_at' => now(),
            'status' => 1,
        ]);
        $user->assignRole(Role::ADMIN);

        $store = Store::create([
            'name' => $prefix . ' Store',
            'user_id' => $user->id,
            'status' => 1,
        ]);
        $tenant = MultiTenant::create(['store_id' => $store->id]);
        $store->update(['tenant_id' => $tenant->id]);
        $user->update(['tenant_id' => $tenant->id]);

        $plan = Plan::first();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_type' => Subscription::TYPE_FREE,
            'plan_amount' => 0,
            'payable_amount' => 0,
            'plan_frequency' => Plan::WEEKLY,
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now()->addDays(30),
            'status' => Subscription::ACTIVE,
        ]);

        return [$user->fresh(), $store->fresh(), $tenant->fresh()];
    }

    private function createCustomRole(string $tenantId, string $name, array $permissions): Role
    {
        $role = Role::withoutGlobalScope('tenant')->create([
            'name' => $name . '_' . uniqid(),
            'display_name' => $name,
            'tenant_id' => $tenantId,
            'guard_name' => 'web',
        ]);
        if (! empty($permissions)) {
            $role->givePermissionTo($permissions);
        }

        return $role;
    }

    private function createStaff(Store $store, Role $role, string $prefix = 'staff'): User
    {
        $staff = User::withoutGlobalScope('tenant')->create([
            'first_name' => $prefix,
            'last_name' => 'User',
            'email' => $prefix . '_' . uniqid() . '@example.com',
            'phone' => (string) random_int(1000000000, 9999999999),
            'password' => Hash::make('Password@123'),
            'email_verified_at' => now(),
            'tenant_id' => $store->tenant_id,
            'status' => 1,
        ]);
        $staff->assignRole($role);
        UserStore::create(['user_id' => $staff->id, 'store_id' => $store->id]);

        return $staff->fresh();
    }

    /**
     * POST-01: Deactivated user's Sanctum tokens are revoked immediately,
     * rejected on protected APIs, and not restored upon reactivation.
     */
    public function test_post01_deactivation_revokes_sanctum_tokens_and_blocks_reused_bearer_tokens(): void
    {
        [$adminA, $storeA, $tenantA] = $this->createTenantAdmin('post01A');
        [$adminB] = $this->createTenantAdmin('post01B');
        $cashierRole = $this->createCustomRole($tenantA->id, 'Cashier01', ['view_pos_screen']);
        $staff = $this->createStaff($storeA, $cashierRole, 'staff01');

        // 1. Staff logs in and gets a real Sanctum Bearer token
        $loginRes = $this->postJson('/api/login', [
            'email' => $staff->email,
            'password' => 'Password@123',
        ])->assertStatus(200);
        $oldToken = $loginRes->json('data.token');
        $this->assertNotEmpty($oldToken);
        $this->assertEquals(1, $staff->tokens()->count());

        // Active token works on protected endpoint
        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/edit-profile')
            ->assertStatus(200);

        // 5. Unauthorized user (Admin B) cannot deactivate Staff A
        $this->actingAs($adminB, 'sanctum')
            ->postJson('/api/users/status/' . $staff->id)
            ->assertStatus(403);
        $this->assertEquals(1, (int) $staff->fresh()->status);

        // 2 & 3. Authorized Store Admin A deactivates Staff A -> all tokens revoked
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users/status/' . $staff->id)
            ->assertStatus(200);
        $this->assertEquals(0, (int) $staff->fresh()->status);
        $this->assertEquals(0, $staff->tokens()->count());

        // Clear cached Sanctum guard user between requests in same test process
        Auth::forgetGuards();

        // Previously issued Bearer token is rejected
        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/edit-profile')
            ->assertStatus(401);

        // 4. Reactivating Staff A does NOT restore the revoked token
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users/status/' . $staff->id)
            ->assertStatus(200);
        $this->assertEquals(1, (int) $staff->fresh()->status);

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/edit-profile')
            ->assertStatus(401);

        // Reactivated user can authenticate again and obtain a fresh working token
        $reLogin = $this->postJson('/api/login', [
            'email' => $staff->email,
            'password' => 'Password@123',
        ])->assertStatus(200);
        $newToken = $reLogin->json('data.token');
        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $newToken)
            ->getJson('/api/edit-profile')
            ->assertStatus(200);

        // Super Admin deactivating Store Admin B also revokes Store Admin B's tokens
        $this->flushHeaders();
        Auth::forgetGuards();
        $superAdmin = User::withoutGlobalScope('tenant')->role(Role::SUPER_ADMIN)->firstOrFail();
        $adminBLogin = $this->postJson('/api/login', [
            'email' => $adminB->email,
            'password' => 'Password@123',
        ])->assertStatus(200);
        $adminBToken = $adminBLogin->json('data.token');
        $this->assertEquals(1, $adminB->tokens()->count());

        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/admin-users/status/' . $adminB->id)
            ->assertStatus(200);
        $this->assertEquals(0, (int) $adminB->fresh()->status);
        $this->assertEquals(0, $adminB->tokens()->count());

        Auth::forgetGuards();
        $this->withHeader('Authorization', 'Bearer ' . $adminBToken)
            ->getJson('/api/edit-profile')
            ->assertStatus(401);
    }

    /**
     * POST-02: Non-Super Admin cannot edit their own role or delegate permissions
     * outside their own permission ceiling, while legitimate Store Admin custom role management works.
     */
    public function test_post02_role_self_escalation_and_permission_ceiling_enforced(): void
    {
        [$adminA, $storeA, $tenantA] = $this->createTenantAdmin('post02A');
        [$adminB, , $tenantB] = $this->createTenantAdmin('post02B');

        // Staff role with only create_roles, edit_roles, and view_pos_screen
        $roleMgrRole = $this->createCustomRole($tenantA->id, 'RoleMgr', ['create_roles', 'edit_roles', 'view_pos_screen']);
        $otherRoleA = $this->createCustomRole($tenantA->id, 'CashierA', ['view_pos_screen']);
        $roleB = $this->createCustomRole($tenantB->id, 'CashierB', ['view_pos_screen']);
        $roleMgr = $this->createStaff($storeA, $roleMgrRole, 'rolemgr');

        $allPermissionIds = Permission::pluck('id')->all();

        // 1. Staff CANNOT edit their own assigned role
        $this->actingAs($roleMgr, 'sanctum')
            ->putJson('/api/roles/' . $roleMgrRole->id, [
                'name' => $roleMgrRole->name,
                'permissions' => ['view_pos_screen'],
            ])->assertStatus(403);

        // 2. Staff CANNOT update another role with permissions they do not possess (e.g. manage_users)
        $res2 = $this->actingAs($roleMgr, 'sanctum')
            ->putJson('/api/roles/' . $otherRoleA->id, [
                'name' => $otherRoleA->name,
                'permissions' => ['view_pos_screen', 'manage_users'],
            ]);
        $this->assertEquals(403, $res2->getStatusCode(), $res2->getContent());

        // 3. Staff CANNOT create a role with all 109 permissions
        $this->actingAs($roleMgr, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'EscalatedAllPerms_' . uniqid(),
                'permissions' => $allPermissionIds,
            ])->assertStatus(403);

        // Staff CAN create a role containing only permissions they already hold
        $this->actingAs($roleMgr, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'SubCashier_' . uniqid(),
                'permissions' => ['view_pos_screen'],
            ])->assertStatus(201);

        // 4. Store Admin CAN create & update legitimate custom roles in their own tenant
        $createRes = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/roles', [
                'name' => 'StoreCashier_' . uniqid(),
                'permissions' => ['view_pos_screen', 'manage_pos_screen'],
            ])->assertStatus(201);
        $createdRoleId = $createRes->json('data.id');

        $this->actingAs($adminA, 'sanctum')
            ->putJson('/api/roles/' . $createdRoleId, [
                'name' => 'StoreCashierUpdated_' . uniqid(),
                'permissions' => ['view_pos_screen', 'manage_pos_screen', 'manage_sale'],
            ])->assertStatus(200);

        // 5. Store Admin CANNOT modify protected global roles (superadmin / admin) or use reserved names
        $superAdminRoleId = Role::withoutGlobalScope('tenant')->where('name', Role::SUPER_ADMIN)->value('id');
        $adminRoleId = Role::withoutGlobalScope('tenant')->where('name', Role::ADMIN)->value('id');
        $this->actingAs($adminA, 'sanctum')
            ->putJson('/api/roles/' . $superAdminRoleId, ['name' => 'hacked', 'permissions' => ['view_pos_screen']])
            ->assertStatus(403);
        $this->actingAs($adminA, 'sanctum')
            ->putJson('/api/roles/' . $adminRoleId, ['name' => 'hacked', 'permissions' => ['view_pos_screen']])
            ->assertStatus(403);
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/roles', ['name' => 'superadmin', 'permissions' => ['view_pos_screen']])
            ->assertStatus(403);

        // 6. Cross-tenant role update rejected
        $this->actingAs($adminA, 'sanctum')
            ->putJson('/api/roles/' . $roleB->id, ['name' => 'HijackedB', 'permissions' => ['view_pos_screen']])
            ->assertStatus(403);
    }

    /**
     * POST-03: AdminRepository::updateAdmin() runs inside one atomic transaction;
     * a failure during store/subscription updates rolls back the user update completely.
     */
    public function test_post03_admin_update_rolls_back_atomically_on_store_update_failure(): void
    {
        [$adminA, $storeA] = $this->createTenantAdmin('post03A');
        $store2 = Store::create([
            'name' => 'Secondary Store',
            'user_id' => $adminA->id,
            'status' => 1,
        ]);
        $tenant2 = MultiTenant::create(['store_id' => $store2->id]);
        $store2->update(['tenant_id' => $tenant2->id]);

        $plan = Plan::first();
        $originalFirstName = $adminA->first_name;

        // Register a temporary Store::updating listener that throws during store disabling
        $shouldThrow = true;
        Store::updating(function () use (&$shouldThrow) {
            if ($shouldThrow) {
                throw new \RuntimeException('Simulated store update failure');
            }
        });

        try {
            $repo = app(AdminRepository::class);
            $threw = false;
            try {
                $repo->updateAdmin([
                    'first_name' => 'ShouldRollback',
                    'last_name' => $adminA->last_name,
                    'email' => $adminA->email,
                    'phone' => $adminA->phone,
                    'plan_id' => $plan->id,
                    'stores' => (string) $storeA->id, // triggers disabling $store2 -> throws
                ], $adminA);
            } catch (UnprocessableEntityHttpException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, 'Expected updateAdmin to throw when store update fails');
        } finally {
            $shouldThrow = false;
        }

        // Verify user's first_name was NOT partially committed
        $this->assertEquals($originalFirstName, $adminA->fresh()->first_name);
        $this->assertEquals(1, (int) $store2->fresh()->status);
    }

    /**
     * POST-04: Store status mutation requires POST (legacy GET is rejected)
     * and enforces store owner authorization.
     */
    public function test_post04_store_status_requires_post_and_enforces_ownership(): void
    {
        [$adminA, $storeA] = $this->createTenantAdmin('post04A');
        [$adminB] = $this->createTenantAdmin('post04B');

        // Create a secondary inactive-tenant store owned by Admin A (so it's not active store)
        $store2 = Store::create([
            'name' => 'Branch 2',
            'user_id' => $adminA->id,
            'status' => 1,
        ]);
        $tenant2 = MultiTenant::create(['store_id' => $store2->id]);
        $store2->update(['tenant_id' => $tenant2->id]);

        // Legacy GET must be rejected and must NOT change store status
        $getRes = $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/change-status/' . $store2->id);
        $this->assertTrue(in_array($getRes->getStatusCode(), [405, 422], true));
        $this->assertEquals(1, (int) $store2->fresh()->status);

        // Another tenant (Admin B) cannot toggle Admin A's store via POST
        $this->actingAs($adminB, 'sanctum')
            ->postJson('/api/change-status/' . $store2->id)
            ->assertStatus(403);
        $this->assertEquals(1, (int) $store2->fresh()->status);

        // Authorized owner (Admin A) CAN toggle secondary store via POST
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/change-status/' . $store2->id)
            ->assertStatus(200);
        $this->assertEquals(0, (int) $store2->fresh()->status);
    }

    /**
     * Store creation authorization: Super Admin provisions full SaaS Store + Store Admin;
     * Store Admin can create branch store only within plan no_of_stores limit; Staff is blocked (403).
     */
    public function test_store_creation_authorization_and_plan_limit_enforcement(): void
    {
        $superAdmin = User::withoutGlobalScope('tenant')->role(Role::SUPER_ADMIN)->firstOrFail();

        // 1. Super Admin creates a Store + Store Admin with custom store_name on a 1-store plan
        $singleStorePlan = Plan::create([
            'name' => 'SingleStore_' . uniqid(),
            'price' => 0,
            'frequency' => Plan::MONTHLY,
            'no_of_stores' => 1,
            'currency_id' => Plan::first()->currency_id,
        ]);
        $ownerEmail = 'store_owner_' . uniqid() . '@example.com';
        $saRes = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/admin-users', [
                'store_name' => 'Kushtia Central Branch',
                'store_code' => 'kushtia-central',
                'country' => 'Bangladesh',
                'plan_id' => $singleStorePlan->id,
                'first_name' => 'Branch',
                'last_name' => 'Owner',
                'email' => $ownerEmail,
                'phone' => (string) random_int(1000000000, 9999999999),
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
            ]);
        $saRes->assertStatus(201);
        $newOwner = User::withoutGlobalScope('tenant')->where('email', $ownerEmail)->firstOrFail();
        $newOwner->forceFill(['email_verified_at' => now()])->save();
        $createdStore = Store::where('user_id', $newOwner->id)->firstOrFail();
        $this->assertEquals('Kushtia Central Branch', $createdStore->name);

        // 2. Store Admin at plan limit (1/1) is blocked from creating another store (403)
        $this->actingAs($newOwner, 'sanctum')
            ->postJson('/api/stores', ['name' => 'Blocked Second Branch'])
            ->assertStatus(403);

        // 3. Store Admin with multi-store plan (no_of_stores = 2) CAN create a 2nd branch store (201)
        $multiPlan = Plan::create([
            'name' => 'MultiStore_' . uniqid(),
            'price' => 0,
            'frequency' => Plan::MONTHLY,
            'no_of_stores' => 2,
            'currency_id' => Plan::first()->currency_id,
        ]);
        Subscription::where('user_id', $newOwner->id)->update(['plan_id' => $multiPlan->id]);

        $this->actingAs($newOwner->fresh(), 'sanctum')
            ->postJson('/api/stores', ['name' => 'Authorized Second Branch'])
            ->assertStatus(201);
        $this->assertEquals(2, Store::where('user_id', $newOwner->id)->count());

        // 4. Staff / Sub-user CANNOT create stores (403)
        $cashierRole = $this->createCustomRole($newOwner->tenant_id, 'CashierStore', ['view_pos_screen']);
        $staff = $this->createStaff($createdStore, $cashierRole, 'staffStore');
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/stores', ['name' => 'Unauthorized Staff Store'])
            ->assertStatus(403);
    }
}
