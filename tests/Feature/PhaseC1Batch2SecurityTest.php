<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use App\Services\PlanFeatureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhaseC1Batch2SecurityTest extends TestCase
{
    private string $token;
    private string $tenantAId;

    private ?User $adminA = null;
    private ?User $subUserA = null;
    private ?User $superAdmin = null;
    private ?Role $staffRoleA = null;
    private ?Store $storeA = null;
    private ?Plan $planA = null;
    private ?PlanFeature $planFeatureA = null;
    private ?Subscription $subscriptionA = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c1b2_tA_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c1b2_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C1B2Admin_{$this->token}", "c1b2.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->superAdmin = $this->makeUser("C1B2Super_{$this->token}", "c1b2.sa.{$this->token}@stockmate.test", null, Role::SUPER_ADMIN);

        $this->staffRoleA = Role::withoutGlobalScopes()->create([
            'name' => "c1b2_staff_{$this->token}",
            'display_name' => 'C1B2 Staff',
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        foreach (['manage_reports', 'manage_adjustments', 'manage_email_templates', 'manage_sms_templates', 'manage_pos_screen', 'manage_roles'] as $permName) {
            $perm = Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
            $this->staffRoleA->givePermissionTo($perm);
        }
        $this->subUserA = $this->makeUser("C1B2Sub_{$this->token}", "c1b2.sub.{$this->token}@stockmate.test", $this->tenantAId, $this->staffRoleA->name);

        $this->storeA = Store::create([
            'name' => "C1B2 Store A {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->subUserA->id, 'store_id' => $this->storeA->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->planA = Plan::create([
            'name' => "C1B2 Plan {$this->token}",
            'price' => 10,
            'frequency' => Plan::MONTHLY,
            'currency_id' => $currency->id,
            'no_of_stores' => 5,
            'trial_days' => 0,
            'assign_while_register' => 0,
        ]);

        // Enable ONLY reports & inventory_management; disable adjustments, emails_support, sms_support, pos_management, roles_permission
        $this->planFeatureA = PlanFeature::create([
            'plan_id' => $this->planA->id,
            'pos_management' => 0,
            'reports' => 1,
            'emails_support' => 0,
            'sms_support' => 0,
            'inventory_management' => 1,
            'adjustments' => 0,
            'roles_permission' => 0,
        ]);

        $this->subscriptionA = Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->planA->id,
            'payment_type' => Subscription::TYPE_FREE,
            'plan_amount' => 10,
            'payable_amount' => 10,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::ACTIVE,
        ]);
    }

    protected function tearDown(): void
    {
        $userIds = array_filter([$this->adminA?->id, $this->subUserA?->id, $this->superAdmin?->id]);
        if (! empty($userIds)) {
            Subscription::whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            Store::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->delete();
            User::whereIn('id', $userIds)->forceDelete();
        }
        if ($this->staffRoleA) {
            DB::table('role_has_permissions')->where('role_id', $this->staffRoleA->id)->delete();
            DB::table('roles')->where('id', $this->staffRoleA->id)->delete();
        }
        if ($this->planFeatureA) {
            $this->planFeatureA->delete();
        }
        if ($this->planA) {
            $this->planA->delete();
        }
        DB::table('tenants')->where('id', $this->tenantAId)->delete();

        parent::tearDown();
    }

    private function makeUser(string $name, string $email, ?string $tenantId, string $roleName): User
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => $email,
            'phone' => '92315' . random_int(1000000, 9999999),
            'password' => Hash::make('Password123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $user->assignRole($roleName);

        return $user;
    }

    public function test_c13_plan_feature_enforcement_blocks_disabled_features_for_admin_and_sub_user_and_allows_enabled(): void
    {
        foreach ([$this->adminA, $this->subUserA] as $actor) {
            // Enabled feature (reports = 1) -> ALLOWED (200)
            $this->actingAs($actor, 'sanctum')
                ->getJson('/api/today-sales-overall-report')
                ->assertStatus(200);

            // Disabled features (0) -> DENIED (403)
            $this->actingAs($actor, 'sanctum')
                ->getJson('/api/adjustments')
                ->assertStatus(403);

            $this->actingAs($actor, 'sanctum')
                ->getJson('/api/mail-templates')
                ->assertStatus(403);

            $this->actingAs($actor, 'sanctum')
                ->getJson('/api/sms-templates')
                ->assertStatus(403);

            $this->actingAs($actor, 'sanctum')
                ->getJson('/api/holds')
                ->assertStatus(403);

            $this->actingAs($actor, 'sanctum')
                ->postJson('/api/roles', ['name' => 'Blocked Role'])
                ->assertStatus(403);

            // /api/config resolves owner's subscription for BOTH admin and sub-user and strips disabled feature permissions
            $configRes = $this->actingAs($actor, 'sanctum')->getJson('/api/config');
            $configRes->assertStatus(200);
            $perms = $configRes->json('data.permissions');
            $this->assertContains('manage_reports', $perms);
            $this->assertNotContains('manage_adjustments', $perms);
            $this->assertNotContains('manage_email_templates', $perms);
            $this->assertNotContains('manage_sms_templates', $perms);
            $this->assertNotContains('manage_pos_screen', $perms);
            $this->assertNotContains('manage_roles', $perms);
        }

        // Flip adjustments -> 1 and verify access is immediately granted for both admin & sub-user
        $this->planFeatureA->update(['adjustments' => 1]);

        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/adjustments')->assertStatus(200);
        $this->actingAs($this->subUserA, 'sanctum')->getJson('/api/adjustments')->assertStatus(200);
    }

    public function test_c13_expired_subscription_blocks_feature_routes_and_super_admin_is_exempt(): void
    {
        // Expire owner's subscription
        $this->subscriptionA->update(['end_date' => now()->subDay()]);

        // Both admin and sub-user are denied on previously enabled reports feature
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/today-sales-overall-report')
            ->assertStatus(403);

        $this->actingAs($this->subUserA, 'sanctum')
            ->getJson('/api/today-sales-overall-report')
            ->assertStatus(403);

        // Super Admin remains exempt in PlanFeatureService
        $service = app(PlanFeatureService::class);
        $this->assertTrue($service->hasFeature($this->superAdmin, 'adjustments'));
        $this->assertTrue($service->hasFeature($this->superAdmin, 'reports'));
        $this->assertSame([], $service->getRestrictedPermissions($this->superAdmin));
    }

    public function test_b201_and_b202_enforce_inventory_management_and_pos_settings_plan_features(): void
    {
        // 1. Disable inventory_management & pos_management -> inventory management + POS settings routes blocked (403)
        $this->planFeatureA->update([
            'inventory_management' => 0,
            'pos_management' => 0,
        ]);

        // B2-01: Inventory management routes blocked when inventory_management = 0
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/brands', ['name' => 'Blocked Brand'])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/product-categories', ['name' => 'Blocked Cat'])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/warehouses', ['name' => 'Blocked Wh'])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/units', ['name' => 'Blocked Unit'])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/products', ['name' => 'Blocked Prod'])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/variations', ['name' => 'Blocked Var'])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/transfers')->assertStatus(403);

        // B2-02: POS & Dual-Screen settings blocked when pos_management = 0
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/pos-settings')->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/pos-settings/update', [])->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/dual-screen-settings')->assertStatus(403);
        $this->actingAs($this->adminA, 'sanctum')->postJson('/api/dual-screen-settings/update', [])->assertStatus(403);

        // Non-POS general settings/taxes remain accessible (not accidentally blocked)
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/taxes')->assertStatus(200);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/payment-methods')->assertStatus(200);

        // 2. Enable inventory_management & pos_management -> authorized users retain access
        $this->planFeatureA->update([
            'inventory_management' => 1,
            'pos_management' => 1,
        ]);

        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/transfers')->assertStatus(200);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/pos-settings')->assertStatus(200);
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/dual-screen-settings')->assertStatus(200);
    }

    public function test_b203_missing_subscription_restricts_paid_permissions_in_config_and_legacy_plan_remains_compatible(): void
    {
        $service = app(PlanFeatureService::class);

        // 1. Delete owner's subscription completely (missing subscription)
        $this->subscriptionA->delete();
        $this->subscriptionA = null;

        // Both Store Admin and sub-user must have ALL paid-feature permissions restricted in /api/config
        foreach ([$this->adminA, $this->subUserA] as $actor) {
            $restricted = $service->getRestrictedPermissions($actor);
            $this->assertContains('manage_pos_screen', $restricted);
            $this->assertContains('manage_reports', $restricted);
            $this->assertContains('manage_products', $restricted);
            $this->assertContains('manage_adjustments', $restricted);
            $this->assertContains('manage_roles', $restricted);

            $configRes = $this->actingAs($actor, 'sanctum')->getJson('/api/config');
            $configRes->assertStatus(200);
            $perms = $configRes->json('data.permissions');
            $this->assertNotContains('manage_pos_screen', $perms);
            $this->assertNotContains('manage_reports', $perms);
            $this->assertNotContains('manage_adjustments', $perms);
            $this->assertNotContains('manage_roles', $perms);
        }

        // 2. Legacy/test plan with active subscription but NO plan_features row stays backward-compatible
        $this->planFeatureA->delete();
        $this->planFeatureA = null;

        $this->subscriptionA = Subscription::create([
            'user_id' => $this->adminA->id,
            'plan_id' => $this->planA->id,
            'payment_type' => Subscription::TYPE_FREE,
            'plan_amount' => 10,
            'payable_amount' => 10,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => Subscription::ACTIVE,
        ]);

        $this->assertTrue($service->hasFeature($this->adminA, 'inventory_management'));
        $this->assertSame([], $service->getRestrictedPermissions($this->adminA));
        $this->actingAs($this->adminA, 'sanctum')->getJson('/api/pos-settings')->assertStatus(200);
    }
}
