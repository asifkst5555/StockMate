<?php

namespace Database\Seeders;

use App\Models\Currency;
use App\Models\MultiTenant;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role as ModelsRole;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DefaultUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Super Admin (Idempotent — never overwrites existing admin accounts or passwords)
        Role::firstOrCreate(
            ['name' => ModelsRole::SUPER_ADMIN],
            ['display_name' => 'Super Admin']
        );
        $admin = User::whereHas('roles', function ($q) {
            $q->where('name', ModelsRole::SUPER_ADMIN);
        })->first();
        if (!$admin) {
            $admin = User::firstOrCreate(
                ['email' => 'superadmin@stockmate.com'],
                [
                    'first_name' => 'Super',
                    'last_name' => 'Admin',
                    'email_verified_at' => Carbon::now(),
                    'password' => Hash::make('Stockmate@1234'),
                    'tenant_id' => null,
                ]
            );
            $admin->assignRole(ModelsRole::SUPER_ADMIN);
        }

        // Admin User
        $adminRole = Role::firstOrCreate(
            ['name' => ModelsRole::ADMIN],
            ['display_name' => ' Admin']
        );
        $allPermissions = Permission::pluck('name', 'id');
        $adminRole->givePermissionTo($allPermissions);

        $user = User::whereHas('roles', function ($q) {
            $q->where('name', ModelsRole::ADMIN);
        })->first();
        if (!$user) {
            $user = User::firstOrCreate(
                ['email' => 'admin@stockmate.com'],
                [
                    'first_name' => 'admin',
                    'last_name' => 'admin',
                    'phone' => '01749-560393',
                    'email_verified_at' => Carbon::now(),
                    'password' => Hash::make('Stockmate@1234'),
                    'tenant_id' => null,
                ]
            );
            $user->assignRole($adminRole);

            $store = Store::firstOrCreate(
                ['name' => 'My Store', 'user_id' => $user->id]
            );
            $tenant = MultiTenant::firstOrCreate(['store_id' => $store->id]);
            $store->update(['tenant_id' => $tenant->id]);
            $user->update(['tenant_id' => $tenant->id]);
        }

        // Currencies (Idempotent: USD default + BDT available)
        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            [
                'name' => 'United States',
                'symbol' => '$',
            ]
        );

        Currency::firstOrCreate(
            ['code' => 'BDT'],
            [
                'name' => 'Bangladesh',
                'symbol' => '৳',
            ]
        );

        // Plan
        $plan = Plan::firstOrCreate(
            ['name' => 'Default Plan'],
            [
                'price' => 0,
                'frequency' => Plan::WEEKLY,
                'assign_while_register' => 1,
                'currency_id' => $currency->id,
            ]
        );

        $plan->planFeature()->create([
            'pos_management' => 1,
            'reports' => 1,
            'emails_support' => 1,
            'sms_support' => 1,
            'inventory_management' => 1,
            'adjustments' => 1,
            'roles_permission' => 1,
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_type' => Subscription::TYPE_FREE,
            'plan_amount' => $plan->price,
            'payable_amount' => $plan->price,
            'plan_frequency' => Plan::WEEKLY,
            'start_date' => Carbon::now(),
            'end_date' => Carbon::now()->addDays(7)->endOfDay(),
            'status' => Subscription::ACTIVE,
        ]);
    }
}
