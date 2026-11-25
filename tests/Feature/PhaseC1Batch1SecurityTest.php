<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesPayment;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PhaseC1Batch1SecurityTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?User $reporterA = null;
    private ?Role $reporterRoleA = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $plan = null;
    private ?Warehouse $warehouseA = null;
    private ?Warehouse $warehouseB = null;
    private ?Customer $customerA = null;
    private ?Customer $customerB = null;
    private ?Sale $saleA = null;
    private ?Sale $saleB = null;
    private ?SalesPayment $paymentA = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'c1b1_tA_' . $this->token;
        $this->tenantBId = 'c1b1_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "c1b1_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "c1b1_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("C1AdminA_{$this->token}", "c1.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("C1AdminB_{$this->token}", "c1.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);

        $this->reporterRoleA = Role::withoutGlobalScopes()->create([
            'name' => "c1_reporter_{$this->token}",
            'display_name' => 'Read Only Reporter',
            'guard_name' => 'web',
            'tenant_id' => $this->tenantAId,
        ]);
        $reportPerm = Permission::firstOrCreate(['name' => 'manage_reports', 'guard_name' => 'web']);
        $this->reporterRoleA->givePermissionTo($reportPerm);

        $this->reporterA = $this->makeUser("C1RepA_{$this->token}", "c1.rep.{$this->token}@stockmate.test", $this->tenantAId, $this->reporterRoleA->name);

        $this->storeA = Store::create(['name' => "C1 Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "C1 Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->reporterA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "C1 Plan {$this->token}",
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

        $this->warehouseA = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C1 Wh A {$this->token}",
            'phone' => '1111111111',
            'country' => 'US',
            'city' => 'NYC',
            'email' => "c1.wha.{$this->token}@stockmate.test",
            'zip_code' => '10001',
        ]);
        $this->warehouseB = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C1 Wh B {$this->token}",
            'phone' => '2222222222',
            'country' => 'US',
            'city' => 'LA',
            'email' => "c1.whb.{$this->token}@stockmate.test",
            'zip_code' => '90001',
        ]);

        $this->customerA = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "C1 Cust A {$this->token}",
            'email' => "c1.ca.{$this->token}@stockmate.test",
            'phone' => '1112223333',
            'country' => 'US',
            'city' => 'NYC',
            'address' => '123 Main St',
        ]);
        $this->customerB = $this->createWithoutTenantScope(Customer::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "C1 Cust B {$this->token}",
            'email' => "c1.cb.{$this->token}@stockmate.test",
            'phone' => '4445556666',
            'country' => 'US',
            'city' => 'LA',
            'address' => '456 Oak Ave',
        ]);

        $today = Carbon::today()->toDateString();

        $this->saleA = $this->createWithoutTenantScope(Sale::class, [
            'tenant_id' => $this->tenantAId,
            'date' => $today,
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA->id,
            'grand_total' => 275.00,
            'paid_amount' => 275.00,
            'received_amount' => 275.00,
            'payment_status' => Sale::PAID,
            'payment_type' => null,
            'status' => Sale::COMPLETED,
            'reference_code' => "SA-C1-{$this->token}",
            'user_id' => $this->adminA->id,
        ]);

        $this->saleB = $this->createWithoutTenantScope(Sale::class, [
            'tenant_id' => $this->tenantBId,
            'date' => $today,
            'customer_id' => $this->customerB->id,
            'warehouse_id' => $this->warehouseB->id,
            'grand_total' => 1500.00,
            'paid_amount' => 1500.00,
            'received_amount' => 1500.00,
            'payment_status' => Sale::PAID,
            'payment_type' => null,
            'status' => Sale::COMPLETED,
            'reference_code' => "SB-C1-{$this->token}",
            'user_id' => $this->adminB->id,
        ]);

        // Ensure a valid PaymentMethod exists (respecting sales_payments_payment_type_foreign FK)
        $pmA = $this->createWithoutTenantScope(\App\Models\PaymentMethod::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "Cash A {$this->token}",
            'status' => 1,
        ]);
        $pmB = $this->createWithoutTenantScope(\App\Models\PaymentMethod::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "Cash B {$this->token}",
            'status' => 1,
        ]);

        // Tenant A payments: 150 + 125 = 275
        $this->paymentA = SalesPayment::create([
            'sale_id' => $this->saleA->id,
            'payment_date' => $today,
            'payment_type' => $pmA->id,
            'amount' => 150.00,
            'received_amount' => 150.00,
        ]);
        SalesPayment::create([
            'sale_id' => $this->saleA->id,
            'payment_date' => $today,
            'payment_type' => $pmA->id,
            'amount' => 125.00,
            'received_amount' => 125.00,
        ]);

        // Tenant B payments: 900 + 600 = 1500
        SalesPayment::create([
            'sale_id' => $this->saleB->id,
            'payment_date' => $today,
            'payment_type' => $pmB->id,
            'amount' => 900.00,
            'received_amount' => 900.00,
        ]);
        SalesPayment::create([
            'sale_id' => $this->saleB->id,
            'payment_date' => $today,
            'payment_type' => $pmB->id,
            'amount' => 600.00,
            'received_amount' => 600.00,
        ]);
    }

    protected function tearDown(): void
    {
        $saleIds = array_filter([$this->saleA?->id, $this->saleB?->id]);
        if (!empty($saleIds)) {
            DB::table('sales_payments')->whereIn('sale_id', $saleIds)->delete();
            DB::table('sales')->whereIn('id', $saleIds)->delete();
        }

        $tenantIds = [$this->tenantAId, $this->tenantBId];
        DB::table('payment_methods')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('customers')->whereIn('tenant_id', $tenantIds)->delete();
        DB::table('warehouses')->whereIn('tenant_id', $tenantIds)->delete();

        $userIds = array_filter([$this->adminA?->id, $this->adminB?->id, $this->reporterA?->id]);
        if (!empty($userIds)) {
            Subscription::whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            Store::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->delete();
            User::whereIn('id', $userIds)->forceDelete();
        }
        if ($this->reporterRoleA) {
            DB::table('role_has_permissions')->where('role_id', $this->reporterRoleA->id)->delete();
            DB::table('roles')->where('id', $this->reporterRoleA->id)->delete();
        }
        if ($this->plan) {
            $this->plan->delete();
        }
        DB::table('tenants')->whereIn('id', $tenantIds)->delete();

        parent::tearDown();
    }

    private function makeUser(string $name, string $email, string $tenantId, string $roleName): User
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => $email,
            'phone' => '92314' . random_int(1000000, 9999999),
            'password' => Hash::make('Password123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $user->assignRole($roleName);

        return $user;
    }

    private function createWithoutTenantScope(string $modelClass, array $attributes)
    {
        return $modelClass::withoutEvents(function () use ($modelClass, $attributes) {
            $instance = new $modelClass();
            $instance->forceFill($attributes);
            $instance->save();
            return $instance;
        });
    }

    public function test_c11_today_sales_overall_report_isolates_sales_payments_per_tenant(): void
    {
        // Tenant A must see ONLY its own payment total (275.00), NOT Tenant B's 1500.00
        $resA = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/today-sales-overall-report');

        $resA->assertStatus(200);
        $dataA = $resA->json('data');
        $this->assertEquals(275.0, (float) $dataA['today_sales_payment_amount']);
        $this->assertEquals(275.0, (float) $dataA['today_sales_total_amount']);

        // Tenant B must see ONLY its own payment total (1500.00), NOT Tenant A's 275.00
        $resB = $this->actingAs($this->adminB, 'sanctum')
            ->getJson('/api/today-sales-overall-report');

        $resB->assertStatus(200);
        $dataB = $resB->json('data');
        $this->assertEquals(1500.0, (float) $dataB['today_sales_payment_amount']);
        $this->assertEquals(1500.0, (float) $dataB['today_sales_total_amount']);
    }

    public function test_c12_manage_reports_role_can_read_and_export_but_cannot_mutate_transactional_resources(): void
    {
        // 1. Legitimate read & export endpoints remain ALLOWED (200)
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson('/api/sales')
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson("/api/sales/{$this->saleA->id}")
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson("/api/sale-info/{$this->saleA->id}")
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson("/api/sales/{$this->saleA->id}/payments")
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson('/api/quotations')
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson('/api/sales-return')
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson('/api/purchases')
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson('/api/purchases-return')
            ->assertStatus(200);
        $this->actingAs($this->reporterA, 'sanctum')
            ->getJson('/api/today-sales-overall-report')
            ->assertStatus(200);

        // Create a real Quotation in Tenant A so route-model binding resolves and permission middleware enforces 403
        $quotationA = $this->createWithoutTenantScope(\App\Models\Quotation::class, [
            'tenant_id' => $this->tenantAId,
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $this->customerA->id,
            'warehouse_id' => $this->warehouseA->id,
            'grand_total' => 100.00,
            'status' => 1,
            'reference_code' => "QT-C1-{$this->token}",
        ]);

        // 2. All write / delete / status-mutation endpoints MUST be DENIED (403) for manage_reports-only user
        $deniedMutations = [
            ['POST', '/api/sales'],
            ['PUT', "/api/sales/{$this->saleA->id}"],
            ['DELETE', "/api/sales/{$this->saleA->id}"],
            ['POST', "/api/sales/{$this->saleA->id}/capture-payment"],
            ['POST', "/api/sales/{$this->paymentA->id}/payment"],
            ['DELETE', "/api/sales/{$this->paymentA->id}/payment"],
            ['POST', '/api/quotations'],
            ['PUT', "/api/quotations/{$quotationA->id}"],
            ['DELETE', "/api/quotations/{$quotationA->id}"],
            ['POST', '/api/sales-return'],
            ['PUT', '/api/sales-return/1'],
            ['DELETE', '/api/sales-return/1'],
            ['GET', '/api/sales-return/paid/1'],
            ['GET', '/api/sales-return-edit/1'],
            ['POST', '/api/purchases'],
            ['PUT', '/api/purchases/1'],
            ['DELETE', '/api/purchases/1'],
            ['POST', '/api/purchases-return'],
            ['PUT', '/api/purchases-return/1'],
            ['DELETE', '/api/purchases-return/1'],
            ['GET', '/api/purchase-return-edit/1'],
        ];

        try {
            foreach ($deniedMutations as [$method, $uri]) {
                $response = $this->actingAs($this->reporterA, 'sanctum')->json($method, $uri, []);
                $this->assertSame(
                    403,
                    $response->status(),
                    "Expected 403 Forbidden for manage_reports-only user on {$method} {$uri}, got {$response->status()}"
                );
            }

            // 3. Verify Sale A, Payment A & Quotation A were NOT deleted or mutated
            $this->assertDatabaseHas('sales', ['id' => $this->saleA->id]);
            $this->assertDatabaseHas('sales_payments', ['id' => $this->paymentA->id]);
            $this->assertDatabaseHas('quotations', ['id' => $quotationA->id]);
        } finally {
            DB::table('quotations')->where('id', $quotationA->id)->delete();
        }
    }
}
