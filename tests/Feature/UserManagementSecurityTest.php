<?php

namespace Tests\Feature;

use App\Models\MultiTenant;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementSecurityTest extends TestCase
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

    private function createTenantCustomRole(string $tenantId, string $name, array $permissions = []): Role
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

    private function createTenantStaff(Store $store, Role $role, string $prefix = 'staff'): User
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
        UserStore::create([
            'user_id' => $staff->id,
            'store_id' => $store->id,
        ]);

        return $staff->fresh();
    }

    public function test_super_admin_can_create_store_admin_and_another_super_admin(): void
    {
        $superAdmin = User::withoutGlobalScope('tenant')->role(Role::SUPER_ADMIN)->firstOrFail();

        // 1. Super Admin creates Store Admin
        $storeAdminEmail = 'new_store_admin_' . uniqid() . '@example.com';
        $resStoreAdmin = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/admin-users', [
                'first_name' => 'Store',
                'last_name' => 'Owner',
                'email' => $storeAdminEmail,
                'phone' => (string) random_int(1000000000, 9999999999),
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
            ]);
        $resStoreAdmin->assertStatus(201);
        $createdStoreAdmin = User::withoutGlobalScope('tenant')->where('email', $storeAdminEmail)->firstOrFail();
        $this->assertTrue($createdStoreAdmin->hasRole(Role::ADMIN));
        $this->assertFalse($createdStoreAdmin->hasRole(Role::SUPER_ADMIN));
        $this->assertNotNull($createdStoreAdmin->tenant_id);

        // 2. Super Admin creates another Super Admin through dedicated workflow
        $newSaEmail = 'new_super_admin_' . uniqid() . '@example.com';
        $resSuperAdmin = $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/super-admins', [
                'first_name' => 'Second',
                'last_name' => 'SuperAdmin',
                'email' => $newSaEmail,
                'phone' => (string) random_int(1000000000, 9999999999),
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
            ]);
        $resSuperAdmin->assertStatus(201);
        $createdSa = User::withoutGlobalScope('tenant')->where('email', $newSaEmail)->firstOrFail();
        $this->assertTrue($createdSa->hasRole(Role::SUPER_ADMIN));
        $this->assertNull($createdSa->tenant_id);

        // Super Admin can list Super Admins
        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/sadmin/super-admins')
            ->assertStatus(200);
    }

    public function test_store_admin_cannot_create_super_admin_or_assign_global_admin_roles(): void
    {
        [$adminA, $storeA] = $this->createTenantAdmin('tenantA');
        $superAdminRoleId = Role::withoutGlobalScope('tenant')->where('name', Role::SUPER_ADMIN)->value('id');
        $adminRoleId = Role::withoutGlobalScope('tenant')->where('name', Role::ADMIN)->value('id');

        // Cannot call dedicated Super Admin endpoint
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/sadmin/super-admins', [
                'first_name' => 'Evil',
                'last_name' => 'SA',
                'email' => 'evil_sa_' . uniqid() . '@example.com',
                'phone' => (string) random_int(1000000000, 9999999999),
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
            ])->assertStatus(403);

        // SEC-01: Cannot assign role_id=1 (superadmin) via /api/users
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users', [
                'first_name' => 'Evil',
                'last_name' => 'Escalation',
                'email' => 'evil_esc1_' . uniqid() . '@example.com',
                'phone' => (string) random_int(1000000000, 9999999999),
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
                'role_id' => $superAdminRoleId,
                'stores' => (string) $storeA->id,
            ])->assertStatus(403);

        // SEC-01: Cannot assign role_id=2 (global admin) via /api/users
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users', [
                'first_name' => 'Evil',
                'last_name' => 'Admin',
                'email' => 'evil_esc2_' . uniqid() . '@example.com',
                'phone' => (string) random_int(1000000000, 9999999999),
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
                'role_id' => $adminRoleId,
                'stores' => (string) $storeA->id,
            ])->assertStatus(403);

        // SEC-11: GET /api/roles does not expose superadmin or admin
        $rolesRes = $this->actingAs($adminA, 'sanctum')->getJson('/api/roles')->assertStatus(200);
        $roleNames = collect($rolesRes->json('data'))->pluck('attributes.name')->all();
        $this->assertNotContains(Role::SUPER_ADMIN, $roleNames);
        $this->assertNotContains(Role::ADMIN, $roleNames);
    }

    public function test_staff_with_manage_reports_or_view_users_only_cannot_mutate_users(): void
    {
        [$adminA, $storeA, $tenantA] = $this->createTenantAdmin('tenantRep');
        $reportRole = $this->createTenantCustomRole($tenantA->id, 'Reporter', ['manage_reports']);
        $viewOnlyRole = $this->createTenantCustomRole($tenantA->id, 'Viewer', ['view_users']);
        $cashierRole = $this->createTenantCustomRole($tenantA->id, 'Cashier', ['view_pos_screen']);

        $reporter = $this->createTenantStaff($storeA, $reportRole, 'reporter');
        $viewer = $this->createTenantStaff($storeA, $viewOnlyRole, 'viewer');
        $cashier = $this->createTenantStaff($storeA, $cashierRole, 'cashier');

        // SEC-06: Reporter can list users (for report filters) but CANNOT create, edit, delete, toggle status, or change password
        $this->actingAs($reporter, 'sanctum')->getJson('/api/users')->assertStatus(200);

        foreach ([$reporter, $viewer] as $restrictedActor) {
            $this->actingAs($restrictedActor, 'sanctum')
                ->postJson('/api/users', [
                    'first_name' => 'Unauthorized',
                    'last_name' => 'User',
                    'email' => 'unauth_' . uniqid() . '@example.com',
                    'phone' => (string) random_int(1000000000, 9999999999),
                    'password' => 'Secret@123',
                    'confirm_password' => 'Secret@123',
                    'role_id' => $cashierRole->id,
                    'stores' => (string) $storeA->id,
                ])->assertStatus(403);

            $this->actingAs($restrictedActor, 'sanctum')
                ->postJson('/api/users/' . $cashier->id, [
                    'first_name' => 'Hacked',
                    'last_name' => 'Cashier',
                    'email' => $cashier->email,
                    'phone' => $cashier->phone,
                    'role_id' => $cashierRole->id,
                    'stores' => (string) $storeA->id,
                ])->assertStatus(403);

            $this->actingAs($restrictedActor, 'sanctum')
                ->deleteJson('/api/users/' . $cashier->id)
                ->assertStatus(403);

            $this->actingAs($restrictedActor, 'sanctum')
                ->postJson('/api/users/status/' . $cashier->id)
                ->assertStatus(403);

            $this->actingAs($restrictedActor, 'sanctum')
                ->postJson('/api/change-user-password', [
                    'user_id' => $cashier->id,
                    'password' => 'NewPass@123',
                    'confirm_password' => 'NewPass@123',
                ])->assertStatus(403);
        }
    }

    public function test_public_registration_always_assigns_store_admin_and_ignores_escalation_fields(): void
    {
        [, , $victimTenant] = $this->createTenantAdmin('victimReg');
        $email = 'public_signup_' . uniqid() . '@example.com';

        $res = $this->postJson('/api/register', [
            'full_name' => 'Public Owner',
            'email' => $email,
            'password' => 'Secret@123',
            'confirm_password' => 'Secret@123',
            'role_id' => 1,
            'tenant_id' => $victimTenant->id,
            'status' => 0,
        ]);

        $res->assertStatus(200);
        $registered = User::withoutGlobalScope('tenant')->where('email', $email)->firstOrFail();
        $this->assertTrue($registered->hasRole(Role::ADMIN));
        $this->assertFalse($registered->hasRole(Role::SUPER_ADMIN));
        $this->assertNotEquals($victimTenant->id, $registered->tenant_id);
        $this->assertNotNull($registered->tenant_id);
    }

    public function test_user_cannot_change_own_role_or_tenant_id(): void
    {
        [$adminA, $storeA, $tenantA] = $this->createTenantAdmin('selfA');
        [, , $tenantB] = $this->createTenantAdmin('selfB');
        $cashierRole = $this->createTenantCustomRole($tenantA->id, 'CashierSelf', ['view_pos_screen']);
        $staff = $this->createTenantStaff($storeA, $cashierRole, 'staffSelf');

        // Cannot modify own role via /api/users/{self}
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users/' . $adminA->id, [
                'first_name' => 'Admin',
                'last_name' => 'Changed',
                'email' => $adminA->email,
                'phone' => $adminA->phone,
                'role_id' => $cashierRole->id,
                'stores' => (string) $storeA->id,
            ])->assertStatus(403);

        // SEC-04: Cannot change own tenant_id or status via /api/update-profile
        $originalTenant = $staff->tenant_id;
        $this->actingAs($staff, 'sanctum')
            ->postJson('/api/update-profile', [
                'first_name' => 'UpdatedName',
                'last_name' => 'User',
                'email' => $staff->email,
                'phone' => $staff->phone,
                'tenant_id' => $tenantB->id,
                'status' => 0,
                'role_id' => 1,
            ])->assertStatus(200);

        $staff->refresh();
        $this->assertEquals('UpdatedName', $staff->first_name);
        $this->assertEquals($originalTenant, $staff->tenant_id);
        $this->assertEquals(1, (int) $staff->status);
        $this->assertFalse($staff->hasRole(Role::SUPER_ADMIN));
    }

    public function test_cross_tenant_isolation_for_passwords_status_stores_and_roles(): void
    {
        [$adminA, $storeA, $tenantA] = $this->createTenantAdmin('isoA');
        [$adminB, $storeB, $tenantB] = $this->createTenantAdmin('isoB');
        $roleA = $this->createTenantCustomRole($tenantA->id, 'StaffA', ['view_pos_screen']);
        $roleB = $this->createTenantCustomRole($tenantB->id, 'StaffB', ['view_pos_screen']);
        $staffB = $this->createTenantStaff($storeB, $roleB, 'staffB');
        $superAdmin = User::withoutGlobalScope('tenant')->role(Role::SUPER_ADMIN)->firstOrFail();

        // SEC-02: Store Admin A cannot reset Tenant B user's password
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/change-user-password', [
                'user_id' => $staffB->id,
                'password' => 'Hacked@123',
                'confirm_password' => 'Hacked@123',
            ])->assertStatus(403);

        // SEC-03: Store Admin A cannot deactivate Tenant B user or Super Admin
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users/status/' . $staffB->id)
            ->assertStatus(403);
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users/status/' . $superAdmin->id)
            ->assertStatus(403);

        // SEC-12: Legacy GET status mutation is rejected (Handler maps 405 -> 422) and does not mutate status
        $legacyGetRes = $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/users/status/' . $staffB->id);
        $this->assertTrue(in_array($legacyGetRes->getStatusCode(), [405, 422], true));
        $this->assertEquals(1, (int) $staffB->fresh()->status);

        // SEC-05: Store Admin A cannot attach new user to Tenant B's store
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users', [
                'first_name' => 'Cross',
                'last_name' => 'Store',
                'email' => 'cross_store_' . uniqid() . '@example.com',
                'phone' => (string) random_int(1000000000, 9999999999),
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
                'role_id' => $roleA->id,
                'stores' => (string) $storeB->id,
            ])->assertStatus(403);

        // SEC-07: Store Admin A cannot view, update, or delete Tenant B's role
        $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/roles/' . $roleB->id)
            ->assertStatus(403);
        $this->actingAs($adminA, 'sanctum')
            ->putJson('/api/roles/' . $roleB->id, [
                'name' => 'HijackedRole',
                'permissions' => ['view_pos_screen'],
            ])->assertStatus(403);
        $this->actingAs($adminA, 'sanctum')
            ->deleteJson('/api/roles/' . $roleB->id)
            ->assertStatus(403);

        // SEC-10: Accessing out-of-scope user returns clean 403 (never 500)
        $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/users/' . $staffB->id)
            ->assertStatus(403);
        $this->actingAs($adminA, 'sanctum')
            ->deleteJson('/api/users/' . $staffB->id)
            ->assertStatus(403);
    }

    public function test_last_active_super_admin_cannot_be_deleted_or_deactivated(): void
    {
        $superAdmin = User::withoutGlobalScope('tenant')->role(Role::SUPER_ADMIN)->firstOrFail();

        // Self-deactivation and last active Super Admin deactivation blocked
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/super-admins/' . $superAdmin->id . '/status')
            ->assertStatus(403);

        // Self-deletion and last active Super Admin deletion blocked
        $this->actingAs($superAdmin, 'sanctum')
            ->deleteJson('/api/sadmin/super-admins/' . $superAdmin->id)
            ->assertStatus(403);

        // Create a second Super Admin, deactivate them, then confirm the sole remaining active SA still cannot be deactivated/deleted
        $secondSa = User::withoutGlobalScope('tenant')->create([
            'first_name' => 'Second',
            'last_name' => 'SA',
            'email' => 'second_sa_' . uniqid() . '@example.com',
            'phone' => (string) random_int(1000000000, 9999999999),
            'password' => Hash::make('Secret@123'),
            'tenant_id' => null,
            'email_verified_at' => now(),
            'status' => 1,
        ]);
        $secondSa->assignRole(Role::SUPER_ADMIN);

        // First SA CAN deactivate second SA while first SA remains active
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/super-admins/' . $secondSa->id . '/status')
            ->assertStatus(200);
        $this->assertEquals(0, (int) $secondSa->fresh()->status);

        // Now second SA (inactive) is blocked by active_user middleware (401) and cannot deactivate/delete the last active SA ($superAdmin)
        $secondSa->refresh();
        $this->actingAs($secondSa, 'sanctum')
            ->postJson('/api/sadmin/super-admins/' . $superAdmin->id . '/status')
            ->assertStatus(401);
        $this->actingAs($secondSa, 'sanctum')
            ->deleteJson('/api/sadmin/super-admins/' . $superAdmin->id)
            ->assertStatus(401);
    }

    public function test_authorized_store_admin_retains_full_own_store_user_management(): void
    {
        [$adminA, $storeA, $tenantA] = $this->createTenantAdmin('validA');
        $cashierRole = $this->createTenantCustomRole($tenantA->id, 'CashierValid', ['view_pos_screen']);
        $staffEmail = 'valid_cashier_' . uniqid() . '@example.com';
        $staffPhone = (string) random_int(1000000000, 9999999999);

        // 1. Create Staff in own store with own custom role
        $createRes = $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users', [
                'first_name' => 'Valid',
                'last_name' => 'Cashier',
                'email' => $staffEmail,
                'phone' => $staffPhone,
                'password' => 'Secret@123',
                'confirm_password' => 'Secret@123',
                'role_id' => $cashierRole->id,
                'stores' => (string) $storeA->id,
            ]);
        $createRes->assertStatus(201);
        $staffId = $createRes->json('data.id');

        // 2. View Staff
        $this->actingAs($adminA, 'sanctum')
            ->getJson('/api/users/' . $staffId)
            ->assertStatus(200);

        // 3. Edit Staff
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users/' . $staffId, [
                'first_name' => 'Updated',
                'last_name' => 'Cashier',
                'email' => $staffEmail,
                'phone' => $staffPhone,
                'role_id' => $cashierRole->id,
                'stores' => (string) $storeA->id,
            ])->assertStatus(200);

        // 4. Toggle Staff Status
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/users/status/' . $staffId)
            ->assertStatus(200);
        $this->assertEquals(0, (int) User::withoutGlobalScope('tenant')->find($staffId)->status);

        // 5. Reset Staff Password
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/change-user-password', [
                'user_id' => $staffId,
                'password' => 'NewSecret@123',
                'confirm_password' => 'NewSecret@123',
            ])->assertStatus(200);

        // 6. Delete Staff
        $this->actingAs($adminA, 'sanctum')
            ->deleteJson('/api/users/' . $staffId)
            ->assertStatus(200);
    }
}
