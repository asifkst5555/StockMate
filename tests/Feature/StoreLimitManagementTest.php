<?php

namespace Tests\Feature;

use App\Models\MultiTenant;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Services\StoreLimitService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StoreLimitManagementTest extends TestCase
{
    use DatabaseTransactions;

    private function createStoreAdminWithPlanLimit(int $planLimit, string $prefix = 'lim'): array
    {
        $plan = Plan::create([
            'name' => $prefix . '_Plan_' . uniqid(),
            'price' => 0,
            'frequency' => Plan::MONTHLY,
            'no_of_stores' => $planLimit,
            'currency_id' => Plan::first()->currency_id,
        ]);

        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $prefix,
            'last_name' => 'Owner',
            'email' => $prefix . '_' . uniqid() . '@example.com',
            'phone' => (string) random_int(1000000000, 9999999999),
            'password' => Hash::make('Password@123'),
            'email_verified_at' => now(),
            'status' => 1,
        ]);
        $user->assignRole(Role::ADMIN);

        $store = Store::create([
            'name' => $prefix . ' Primary Store',
            'user_id' => $user->id,
            'status' => 1,
        ]);
        $tenant = MultiTenant::create(['store_id' => $store->id]);
        $store->update(['tenant_id' => $tenant->id]);
        $user->update(['tenant_id' => $tenant->id]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_type' => Subscription::TYPE_FREE,
            'plan_amount' => 0,
            'payable_amount' => 0,
            'plan_frequency' => Plan::MONTHLY,
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now()->addDays(30),
            'status' => Subscription::ACTIVE,
        ]);

        return [$user->fresh(), $plan->fresh(), $store->fresh()];
    }

    public function test_plan_limit_1_and_plan_limit_3_enforcement(): void
    {
        // Plan limit 1: already has 1 primary store -> 2nd store blocked (403)
        [$admin1] = $this->createStoreAdminWithPlanLimit(1, 'plan1');
        $this->actingAs($admin1, 'sanctum')
            ->postJson('/api/stores', ['name' => 'Blocked Branch 2'])
            ->assertStatus(403);

        // Plan limit 3: can create 2 more branches (total 3), 4th blocked (403)
        [$admin3] = $this->createStoreAdminWithPlanLimit(3, 'plan3');
        $this->actingAs($admin3, 'sanctum')
            ->postJson('/api/stores', ['name' => 'Branch 2'])
            ->assertStatus(201);
        $this->actingAs($admin3, 'sanctum')
            ->postJson('/api/stores', ['name' => 'Branch 3'])
            ->assertStatus(201);
        $this->actingAs($admin3, 'sanctum')
            ->postJson('/api/stores', ['name' => 'Branch 4 Blocked'])
            ->assertStatus(403);

        $summary = app(StoreLimitService::class)->getStoreLimitSummary($admin3->fresh());
        $this->assertEquals(3, $summary['effective_store_limit']);
        $this->assertEquals(3, $summary['total_store_count']);
        $this->assertEquals(0, $summary['remaining_store_slots']);
    }

    public function test_super_admin_per_customer_override_5_unlimited_and_removal(): void
    {
        $superAdmin = User::withoutGlobalScope('tenant')->role(Role::SUPER_ADMIN)->firstOrFail();
        [$adminA] = $this->createStoreAdminWithPlanLimit(1, 'ovrA');

        // 1. Set per-customer override to 5
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/admin-users/' . $adminA->id . '/store-limit', [
                'store_limit_override' => 5,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.effective_store_limit', 5)
            ->assertJsonPath('data.remaining_store_slots', 4);

        // Verify /api/config reflects override 5
        $this->actingAs($adminA->fresh(), 'sanctum')
            ->getJson('/api/config')
            ->assertStatus(200)
            ->assertJsonPath('data.no_of_stores', 5)
            ->assertJsonPath('data.is_unlimited_stores', false);

        // Admin A can create stores #2..#5, and #6 is blocked
        for ($i = 2; $i <= 5; $i++) {
            $this->actingAs($adminA->fresh(), 'sanctum')
                ->postJson('/api/stores', ['name' => "Override Branch {$i}"])
                ->assertStatus(201);
        }
        $this->actingAs($adminA->fresh(), 'sanctum')
            ->postJson('/api/stores', ['name' => 'Override Branch 6 Blocked'])
            ->assertStatus(403);

        // 2. Enable Unlimited override (-1) -> store #6 now succeeds!
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/admin-users/' . $adminA->id . '/store-limit', [
                'store_limit_override' => -1,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.is_unlimited', true)
            ->assertJsonPath('data.remaining_store_slots', null);

        $this->actingAs($adminA->fresh(), 'sanctum')
            ->postJson('/api/stores', ['name' => 'Unlimited Branch 6'])
            ->assertStatus(201);

        // 3. Remove override (null) -> reverts to plan limit 1 -> store #7 blocked
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/admin-users/' . $adminA->id . '/store-limit', [
                'store_limit_override' => null,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.store_limit_override', null)
            ->assertJsonPath('data.effective_store_limit', 1);

        $this->actingAs($adminA->fresh(), 'sanctum')
            ->postJson('/api/stores', ['name' => 'Reverted Branch 7 Blocked'])
            ->assertStatus(403);

        // Invalid override value (0 or -2) rejected with 422
        $this->actingAs($superAdmin, 'sanctum')
            ->postJson('/api/sadmin/admin-users/' . $adminA->id . '/store-limit', [
                'store_limit_override' => 0,
            ])->assertStatus(422);
    }

    public function test_unauthorized_store_limit_manipulation_is_blocked(): void
    {
        [$adminA] = $this->createStoreAdminWithPlanLimit(1, 'unauthLim');

        // Store Admin cannot call Super Admin store-limit endpoint (403)
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/sadmin/admin-users/' . $adminA->id . '/store-limit', [
                'store_limit_override' => -1,
            ])->assertStatus(403);

        // Store Admin cannot mass-assign store_limit_override via /api/update-profile
        $this->actingAs($adminA, 'sanctum')
            ->postJson('/api/update-profile', [
                'first_name' => $adminA->first_name,
                'last_name' => $adminA->last_name,
                'email' => $adminA->email,
                'phone' => $adminA->phone,
                'store_limit_override' => -1,
            ])->assertStatus(200);

        $this->assertNull($adminA->fresh()->store_limit_override);

        // Existing local Store Admin (#2) remains intact with exact owned store count
        $realAdmin = User::withoutGlobalScope('tenant')->find(2);
        $this->assertNotNull($realAdmin);
        $this->assertNull($realAdmin->store_limit_override);
        $summary = app(StoreLimitService::class)->getStoreLimitSummary($realAdmin);
        $expectedStoreCount = Store::where('user_id', $realAdmin->id)->count();
        $expectedPlanLimit = (int) Subscription::with('plan')->where('user_id', $realAdmin->id)->where('status', Subscription::ACTIVE)->latest('id')->first()->plan->no_of_stores;
        $this->assertEquals($expectedStoreCount, $summary['total_store_count']);
        $this->assertEquals($expectedPlanLimit, $summary['plan_store_limit']);
    }
}
