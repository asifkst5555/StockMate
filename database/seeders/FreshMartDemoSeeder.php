<?php

namespace Database\Seeders;

use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\MultiTenant;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SalesPayment;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use App\Repositories\AdminRepository;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class FreshMartDemoSeeder extends Seeder
{
    public const OWNER_EMAIL = 'owner@freshmart.demo';
    public const DEMO_PASSWORD_OWNER = 'FreshMart@Owner2026';
    public const DEMO_PASSWORD_MANAGER = 'FreshMart@Mgr2026';
    public const DEMO_PASSWORD_INVENTORY = 'FreshMart@Inv2026';
    public const DEMO_PASSWORD_CASHIER = 'FreshMart@Pos2026';

    public function run(): void
    {
        // Idempotency guard: if FreshMart Owner already has all 3 branches populated with products, skip cleanly
        $existingOwner = User::withoutGlobalScope('tenant')->where('email', self::OWNER_EMAIL)->first();
        if ($existingOwner) {
            $existingBranches = Store::where('user_id', $existingOwner->id)->pluck('tenant_id');
            if ($existingBranches->count() >= 3) {
                $existingProducts = Product::withoutGlobalScope('tenant')
                    ->whereIn('tenant_id', $existingBranches)
                    ->count();
                if ($existingProducts >= 450) {
                    $this->command?->info('FreshMart demo dataset already seeded — skipping to preserve idempotency.');
                    return;
                }
            }
        }

        $bdtCurrency = Currency::firstOrCreate(
            ['code' => 'BDT'],
            ['name' => 'Bangladesh', 'symbol' => '৳']
        );

        $defaultPlan = Plan::where('assign_while_register', true)->first() ?? Plan::firstOrFail();

        DB::transaction(function () use ($bdtCurrency, $defaultPlan) {
            // 1. Create or retrieve dedicated FreshMart Owner (Store Admin) with store_limit_override = 3
            $owner = User::withoutGlobalScope('tenant')->firstOrCreate(
                ['email' => self::OWNER_EMAIL],
                [
                    'first_name' => 'Mahmudul',
                    'last_name' => 'Hasan (FreshMart Owner)',
                    'phone' => '8801700001001',
                    'password' => Hash::make(self::DEMO_PASSWORD_OWNER),
                    'email_verified_at' => now(),
                    'status' => 1,
                ]
            );
            if (! $owner->hasRole(Role::ADMIN)) {
                $owner->assignRole(Role::ADMIN);
            }
            $owner->forceFill(['store_limit_override' => 3])->save();

            // Active Subscription for FreshMart Owner (preserving global default plan unchanged)
            $hasSub = Subscription::where('user_id', $owner->id)->where('status', Subscription::ACTIVE)->exists();
            if (! $hasSub) {
                Subscription::create([
                    'user_id' => $owner->id,
                    'plan_id' => $defaultPlan->id,
                    'payment_type' => Subscription::TYPE_MANUAL,
                    'plan_amount' => $defaultPlan->price,
                    'payable_amount' => $defaultPlan->price,
                    'plan_frequency' => $defaultPlan->frequency,
                    'start_date' => Carbon::now()->subDays(30),
                    'end_date' => Carbon::now()->addYear(),
                    'status' => Subscription::ACTIVE,
                ]);
            }

            // 2. Provision the 3 FreshMart Branches under the SAME owner
            $branchSpecs = [
                'central' => [
                    'name' => 'FreshMart Central',
                    'code' => 'FMC',
                    'city' => 'Kushtia',
                    'address' => 'NS Road, Kushtia Sadar, Kushtia - 7000',
                    'phone' => '01700-110001',
                    'main_wh' => 'Central Main Warehouse',
                    'floor_wh' => 'Central Retail Floor',
                    'stock_multiplier' => 1.0,
                ],
                'bheramara' => [
                    'name' => 'FreshMart Bheramara',
                    'code' => 'FMB',
                    'city' => 'Bheramara, Kushtia',
                    'address' => 'Station Road, Bheramara, Kushtia - 7040',
                    'phone' => '01700-110002',
                    'main_wh' => 'Bheramara Main Store',
                    'floor_wh' => 'Bheramara Backroom',
                    'stock_multiplier' => 0.40,
                ],
                'mirpur' => [
                    'name' => 'FreshMart Mirpur',
                    'code' => 'FMM',
                    'city' => 'Mirpur, Kushtia',
                    'address' => 'Mirpur Bazar, Kushtia - 7030',
                    'phone' => '01700-110003',
                    'main_wh' => 'Mirpur Main Store',
                    'floor_wh' => 'Mirpur Backroom',
                    'stock_multiplier' => 0.55,
                ],
            ];

            $branches = [];
            $adminRepo = App::make(AdminRepository::class);

            foreach ($branchSpecs as $key => $spec) {
                $store = Store::where('user_id', $owner->id)->where('name', $spec['name'])->first();
                if (! $store) {
                    $store = Store::create([
                        'name' => $spec['name'],
                        'user_id' => $owner->id,
                        'status' => 1,
                    ]);
                    $tenant = MultiTenant::create(['store_id' => $store->id]);
                    $store->update(['tenant_id' => $tenant->id]);

                    $adminRepo->defaultSettings($tenant->id, [
                        'currency' => $bdtCurrency->id,
                        'country' => 'Bangladesh',
                        'store_email' => strtolower($spec['code']) . '@freshmart.demo',
                        'store_phone' => $spec['phone'],
                        'store_address' => $spec['address'],
                        'store_code' => $spec['code'],
                    ]);
                }

                // Ensure BDT currency on FreshMart store
                Setting::withoutGlobalScope('tenant')->updateOrCreate(
                    ['tenant_id' => $store->tenant_id, 'key' => 'currency'],
                    ['value' => (string) $bdtCurrency->id]
                );

                // Ensure active payment methods exist for POS
                foreach (['Cash', 'bKash / Mobile Banking', 'Card / Bank Transfer'] as $pmName) {
                    PaymentMethod::withoutGlobalScope('tenant')->firstOrCreate(
                        ['tenant_id' => $store->tenant_id, 'name' => $pmName],
                        ['status' => 1]
                    );
                }

                $branches[$key] = [
                    'store' => $store->fresh(),
                    'tenant_id' => $store->tenant_id,
                    'spec' => $spec,
                ];
            }

            // Set Owner's active store to FreshMart Central
            $owner->update(['tenant_id' => $branches['central']['tenant_id']]);

            // 3. Create Scoped Roles for FreshMart (anchored to Central tenant)
            $centralTenantId = $branches['central']['tenant_id'];
            $managerRole = $this->ensureRole($centralTenantId, 'freshmart_branch_manager', 'Branch Manager', [
                'manage_dashboard', 'view_dashboard',
                'manage_products', 'view_products', 'create_products', 'edit_products',
                'manage_product_categories', 'view_product_categories', 'create_product_categories',
                'manage_brands', 'view_brands',
                'manage_units', 'view_units',
                'manage_warehouses', 'view_warehouses',
                'manage_purchase', 'view_purchase', 'create_purchase', 'edit_purchase',
                'manage_sale', 'view_sale', 'create_sale', 'edit_sale',
                'manage_sale_return', 'view_sale_return', 'create_sale_return',
                'manage_pos_screen', 'view_pos_screen',
                'manage_customers', 'view_customers', 'create_customers', 'edit_customers',
                'manage_suppliers', 'view_suppliers', 'create_suppliers',
                'manage_adjustments', 'view_adjustments', 'create_adjustments',
                'manage_transfers', 'view_transfers', 'create_transfers',
                'manage_expenses', 'view_expenses', 'create_expenses',
                'manage_expense_categories', 'view_expense_categories',
                'manage_reports', 'edit_reports',
            ]);

            $inventoryRole = $this->ensureRole($centralTenantId, 'freshmart_inventory_officer', 'Inventory Officer', [
                'manage_dashboard', 'view_dashboard',
                'manage_products', 'view_products', 'create_products', 'edit_products',
                'manage_product_categories', 'view_product_categories',
                'manage_brands', 'view_brands',
                'manage_units', 'view_units',
                'manage_warehouses', 'view_warehouses',
                'manage_purchase', 'view_purchase', 'create_purchase',
                'manage_adjustments', 'view_adjustments', 'create_adjustments',
                'manage_transfers', 'view_transfers', 'create_transfers',
                'manage_suppliers', 'view_suppliers',
                'manage_reports',
            ]);

            $cashierRole = $this->ensureRole($centralTenantId, 'freshmart_cashier', 'Cashier', [
                'manage_pos_screen', 'view_pos_screen',
                'manage_sale', 'view_sale', 'create_sale',
                'view_products', 'manage_products',
                'manage_customers', 'view_customers', 'create_customers',
            ]);

            // 4. Create the 7 Staff Demo Users with strict branch scoping via user_stores
            $staffUsers = [];
            $staffSpecs = [
                'manager_central' => ['first' => 'Tanvir', 'last' => 'Ahmed', 'email' => 'manager.central@freshmart.demo', 'phone' => '8801700001002', 'password' => self::DEMO_PASSWORD_MANAGER, 'role' => $managerRole, 'branches' => ['central']],
                'manager_bheramara' => ['first' => 'Farhana', 'last' => 'Islam', 'email' => 'manager.bheramara@freshmart.demo', 'phone' => '8801700001003', 'password' => self::DEMO_PASSWORD_MANAGER, 'role' => $managerRole, 'branches' => ['bheramara']],
                'manager_mirpur' => ['first' => 'Rafiqul', 'last' => 'Karim', 'email' => 'manager.mirpur@freshmart.demo', 'phone' => '8801700001004', 'password' => self::DEMO_PASSWORD_MANAGER, 'role' => $managerRole, 'branches' => ['mirpur']],
                'inventory_officer' => ['first' => 'Nusrat', 'last' => 'Jahan', 'email' => 'inventory@freshmart.demo', 'phone' => '8801700001005', 'password' => self::DEMO_PASSWORD_INVENTORY, 'role' => $inventoryRole, 'branches' => ['central', 'bheramara', 'mirpur']],
                'cashier_central' => ['first' => 'Sajib', 'last' => 'Mia', 'email' => 'cashier.central@freshmart.demo', 'phone' => '8801700001006', 'password' => self::DEMO_PASSWORD_CASHIER, 'role' => $cashierRole, 'branches' => ['central']],
                'cashier_bheramara' => ['first' => 'Mitu', 'last' => 'Akter', 'email' => 'cashier.bheramara@freshmart.demo', 'phone' => '8801700001007', 'password' => self::DEMO_PASSWORD_CASHIER, 'role' => $cashierRole, 'branches' => ['bheramara']],
                'cashier_mirpur' => ['first' => 'Ariful', 'last' => 'Haque', 'email' => 'cashier.mirpur@freshmart.demo', 'phone' => '8801700001008', 'password' => self::DEMO_PASSWORD_CASHIER, 'role' => $cashierRole, 'branches' => ['mirpur']],
            ];

            foreach ($staffSpecs as $key => $s) {
                $primaryBranch = $branches[$s['branches'][0]];
                $u = User::withoutGlobalScope('tenant')->firstOrCreate(
                    ['email' => $s['email']],
                    [
                        'first_name' => $s['first'],
                        'last_name' => $s['last'],
                        'phone' => $s['phone'],
                        'password' => Hash::make($s['password']),
                        'tenant_id' => $primaryBranch['tenant_id'],
                        'email_verified_at' => now(),
                        'status' => 1,
                    ]
                );
                $u->syncRoles([$s['role']->id]);
                UserStore::where('user_id', $u->id)->delete();
                foreach ($s['branches'] as $bKey) {
                    UserStore::create([
                        'user_id' => $u->id,
                        'store_id' => $branches[$bKey]['store']->id,
                    ]);
                }
                $staffUsers[$key] = $u;
            }

            // 5. Seed Complete Coherent Grocery Dataset for Each of the 3 Branches
            $catalog = $this->groceryCatalog();
            $suppliersList = $this->suppliersData();
            $expenseCategoriesList = ['Rent', 'Electricity', 'Staff Salaries', 'Transport', 'Packaging', 'Maintenance'];

            foreach ($branches as $bKey => $bData) {
                $this->seedBranchData(
                    $bKey,
                    $bData['tenant_id'],
                    $bData['spec'],
                    $owner,
                    $staffUsers['manager_' . $bKey],
                    $staffUsers['cashier_' . $bKey],
                    $staffUsers['inventory_officer'],
                    $catalog,
                    $suppliersList,
                    $expenseCategoriesList
                );
            }
        });
    }

    private function ensureRole(string $tenantId, string $name, string $displayName, array $permissions): Role
    {
        $role = Role::withoutGlobalScope('tenant')->firstOrCreate(
            ['name' => $name],
            [
                'display_name' => $displayName,
                'tenant_id' => $tenantId,
                'guard_name' => 'web',
            ]
        );
        $role->syncPermissions($permissions);

        return $role;
    }

    private function seedBranchData(
        string $branchKey,
        string $tenantId,
        array $spec,
        User $owner,
        User $manager,
        User $cashier,
        User $inventoryOfficer,
        array $catalog,
        array $suppliersList,
        array $expenseCategoriesList
    ): void {
        // Warehouses: Reuse default warehouse as Main Hub + create Retail Floor / Backroom for intra-branch transfers
        $mainWh = Warehouse::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->first();
        if ($mainWh) {
            $mainWh->update([
                'name' => $spec['main_wh'],
                'phone' => $spec['phone'],
                'country' => 'Bangladesh',
                'city' => $spec['city'],
                'email' => strtolower($spec['code']) . '.hub@freshmart.demo',
                'zip_code' => '7030',
            ]);
        } else {
            $mainWh = Warehouse::withoutGlobalScope('tenant')->create([
                'tenant_id' => $tenantId,
                'name' => $spec['main_wh'],
                'phone' => $spec['phone'],
                'country' => 'Bangladesh',
                'city' => $spec['city'],
                'email' => strtolower($spec['code']) . '.hub@freshmart.demo',
                'zip_code' => '7030',
            ]);
        }

        $floorWh = Warehouse::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => $spec['floor_wh']],
            [
                'phone' => $spec['phone'],
                'country' => 'Bangladesh',
                'city' => $spec['city'],
                'email' => strtolower($spec['code']) . '.floor@freshmart.demo',
                'zip_code' => '7030',
            ]
        );

        // Base Units & Units
        $baseKg = BaseUnit::withoutGlobalScope('tenant')->firstOrCreate(['tenant_id' => $tenantId, 'name' => 'kilogram'], ['is_default' => 1]);
        $basePiece = BaseUnit::withoutGlobalScope('tenant')->firstOrCreate(['tenant_id' => $tenantId, 'name' => 'piece'], ['is_default' => 1]);
        $baseLiter = BaseUnit::withoutGlobalScope('tenant')->firstOrCreate(['tenant_id' => $tenantId, 'name' => 'liter'], ['is_default' => 1]);

        $unitDefs = [
            '250g' => ['250 Gram', '250g', $baseKg->id],
            '500g' => ['500 Gram', '500g', $baseKg->id],
            '1kg' => ['1 Kilogram', '1kg', $baseKg->id],
            '2kg' => ['2 Kilogram', '2kg', $baseKg->id],
            '5kg' => ['5 Kilogram', '5kg', $baseKg->id],
            '500ml' => ['500 Milliliter', '500ml', $baseLiter->id],
            '1L' => ['1 Liter', '1L', $baseLiter->id],
            '2L' => ['2 Liter', '2L', $baseLiter->id],
            'Piece' => ['Piece', 'pc', $basePiece->id],
            'Dozen' => ['Dozen (12 pcs)', 'dz', $basePiece->id],
            'Carton' => ['Carton', 'ctn', $basePiece->id],
        ];
        $units = [];
        foreach ($unitDefs as $key => [$uName, $short, $baseId]) {
            $units[$key] = Unit::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'short_name' => $short],
                ['name' => $uName, 'base_unit' => $baseId]
            );
        }

        // Brands
        $brandNames = [
            'ACI Pure', 'Teer', 'Rupchanda', 'Fresh', 'Pran', 'Radhuni',
            'Aarong Dairy', 'Ispahani', 'Olympic', 'Bashundhara', 'Marks',
            'Kazi Farms', 'Local Fresh', 'Square', 'Unilever BD', 'Nestle BD',
        ];
        $brands = [];
        foreach ($brandNames as $bName) {
            $brands[$bName] = Brand::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $bName],
                ['description' => $bName . ' Bangladesh']
            );
        }

        // Categories & 162 Grocery Products (18 categories x 9 SKUs)
        $categories = [];
        $products = [];
        $skuCounter = 1;

        foreach ($catalog as $catName => $items) {
            $cat = ProductCategory::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $catName]
            );
            $categories[$catName] = $cat;

            foreach ($items as $item) {
                [$prodName, $brandName, $unitKey, $cost, $price, $baseStock, $alert, $expiryDays] = $item;
                $sku = sprintf('%s-%03d', $spec['code'], $skuCounter);
                $barcode = sprintf('894%s%05d', $branchKey === 'central' ? '1' : ($branchKey === 'bheramara' ? '2' : '3'), $skuCounter);
                $unit = $units[$unitKey] ?? $units['Piece'];
                $brand = $brands[$brandName] ?? $brands['Local Fresh'];

                $mainProd = MainProduct::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $sku],
                    [
                        'name' => $prodName,
                        'product_unit' => (string) $unit->id,
                        'product_type' => 1,
                    ]
                );

                $expiryDate = $expiryDays !== null ? Carbon::now()->addDays($expiryDays)->toDateString() : null;

                $product = Product::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenantId, 'code' => $sku],
                    [
                        'main_product_id' => $mainProd->id,
                        'name' => $prodName,
                        'product_code' => $barcode,
                        'barcode_symbol' => Product::CODE128,
                        'expiry_date' => $expiryDate,
                        'product_category_id' => $cat->id,
                        'brand_id' => $brand->id,
                        'product_cost' => $cost,
                        'product_price' => $price,
                        'product_unit' => (string) $unit->id,
                        'sale_unit' => (string) $unit->id,
                        'purchase_unit' => (string) $unit->id,
                        'stock_alert' => (string) $alert,
                        'order_tax' => 0,
                        'tax_type' => 1,
                        'notes' => 'FreshMart Grocery Demo SKU',
                    ]
                );

                // Compute realistic branch-specific opening stock (including low stock and out-of-stock examples)
                $openingQty = (int) round($baseStock * $spec['stock_multiplier']);
                if ($skuCounter % 29 === 0) {
                    $openingQty = 0; // Intentional Out-of-Stock SKU for demo
                } elseif ($skuCounter % 13 === 0) {
                    $openingQty = max(2, (int) floor($alert * 0.6)); // Intentional Low-Stock SKU for demo
                }

                $ms = ManageStock::withoutGlobalScope('tenant')->firstOrCreate(
                    ['tenant_id' => $tenantId, 'warehouse_id' => $mainWh->id, 'product_id' => $product->id],
                    ['quantity' => $openingQty, 'alert' => $openingQty <= $alert ? 1 : 0]
                );

                if ($ms->wasRecentlyCreated && $openingQty > 0) {
                    StockMovement::withoutGlobalScope('tenant')->create([
                        'tenant_id' => $tenantId,
                        'warehouse_id' => $mainWh->id,
                        'product_id' => $product->id,
                        'type' => 'opening_stock',
                        'quantity_change' => $openingQty,
                        'quantity_before' => 0,
                        'quantity_after' => $openingQty,
                        'unit_cost' => $cost,
                        'reference_type' => 'opening',
                        'reference_id' => $product->id,
                        'note' => 'Initial branch opening balance',
                        'user_id' => $owner->id,
                    ]);
                }

                $products[] = $product;
                $skuCounter++;
            }
        }

        // Suppliers (12 per branch)
        $suppliers = [];
        foreach ($suppliersList as $idx => $sup) {
            $suppliers[] = Supplier::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'email' => strtolower($spec['code']) . '.' . $sup['email']],
                [
                    'name' => $sup['name'],
                    'phone' => $sup['phone'],
                    'country' => 'Bangladesh',
                    'city' => $sup['city'],
                    'address' => $sup['address'],
                ]
            );
        }

        // Customers (15 per branch = 45 total across 3 branches)
        $customers = [];
        $customerNames = [
            'Abdul Karim', 'Nasrin Akter', 'Kamrul Islam', 'Sharmin Sultana', 'Mizanur Rahman',
            'Rehana Begum', 'Tariqul Islam', 'Farzana Yasmin', 'Sohel Rana', 'Jannatul Ferdous',
            'Habibur Rahman', 'Monira Khatun', 'Ashraful Alam', 'Sadia Afrin', 'Jahangir Hossain',
        ];
        foreach ($customerNames as $idx => $cName) {
            $customers[] = Customer::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'email' => strtolower($spec['code']) . '.cust' . ($idx + 1) . '@freshmart.demo'],
                [
                    'name' => $cName,
                    'phone' => sprintf('01700-2%s%04d', $branchKey === 'central' ? '1' : ($branchKey === 'bheramara' ? '2' : '3'), $idx + 1),
                    'country' => 'Bangladesh',
                    'city' => $spec['city'],
                    'address' => 'House ' . ($idx + 12) . ', ' . $spec['city'],
                ]
            );
        }

        // Resolve branch payment method IDs (FK to payment_methods.id)
        $pmIds = PaymentMethod::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->pluck('id', 'name')
            ->all();
        $cashPmId = $pmIds['Cash'] ?? array_values($pmIds)[0];
        $bkashPmId = $pmIds['bKash / Mobile Banking'] ?? $cashPmId;
        $bankPmId = $pmIds['Card / Bank Transfer'] ?? $cashPmId;

        // 6. Purchases (12 per branch = 36 total: 8 Received, 2 Pending, 2 Ordered; Paid / Partial / Unpaid)
        $existingPurchases = Purchase::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->count();
        if ($existingPurchases === 0) {
            for ($p = 0; $p < 12; $p++) {
                $status = $p < 8 ? Purchase::RECEIVED : ($p < 10 ? Purchase::PENDING : Purchase::ORDERED);
                $payStatus = $p % 3 === 0 ? Purchase::PAID : ($p % 3 === 1 ? Purchase::PARTIAL : Purchase::UNPAID);
                $date = Carbon::now()->subDays(28 - ($p * 2));
                $supplier = $suppliers[$p % count($suppliers)];
                $refCode = sprintf('PU-%s-%04d', $spec['code'], $p + 1);

                $lineProducts = [
                    $products[($p * 5) % count($products)],
                    $products[($p * 5 + 1) % count($products)],
                    $products[($p * 5 + 2) % count($products)],
                ];

                $subTotalSum = 0;
                $preparedItems = [];
                foreach ($lineProducts as $lp) {
                    $qty = (int) max(10, round((20 + ($p * 3)) * $spec['stock_multiplier']));
                    $lineTotal = round($lp->product_cost * $qty, 2);
                    $subTotalSum += $lineTotal;
                    $preparedItems[] = ['product' => $lp, 'qty' => $qty, 'sub_total' => $lineTotal];
                }

                $discount = $p % 4 === 0 ? 150 : 0;
                $shipping = 200;
                $grandTotal = round($subTotalSum - $discount + $shipping, 2);
                $paidAmount = $payStatus === Purchase::PAID ? $grandTotal : ($payStatus === Purchase::PARTIAL ? round($grandTotal * 0.5, 2) : 0);

                $purchase = Purchase::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $tenantId,
                    'date' => $date->toDateString(),
                    'supplier_id' => $supplier->id,
                    'warehouse_id' => $mainWh->id,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'discount' => $discount,
                    'shipping' => $shipping,
                    'grand_total' => $grandTotal,
                    'received_amount' => $paidAmount,
                    'paid_amount' => $paidAmount,
                    'payment_type' => $p % 2 === 0 ? $cashPmId : $bankPmId,
                    'payment_status' => $payStatus,
                    'status' => $status,
                    'notes' => 'FreshMart Demo Restock Order #' . ($p + 1),
                    'reference_code' => $refCode,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);

                foreach ($preparedItems as $pi) {
                    PurchaseItem::create([
                        'purchase_id' => $purchase->id,
                        'product_id' => $pi['product']->id,
                        'product_cost' => $pi['product']->product_cost,
                        'net_unit_cost' => $pi['product']->product_cost,
                        'tax_type' => Purchase::EXCLUSIVE,
                        'tax_value' => 0,
                        'tax_amount' => 0,
                        'discount_type' => Purchase::FIXED,
                        'discount_value' => 0,
                        'discount_amount' => 0,
                        'purchase_unit' => (int) $pi['product']->purchase_unit,
                        'quantity' => $pi['qty'],
                        'sub_total' => $pi['sub_total'],
                    ]);

                    // Only RECEIVED purchases increment stock & ledger
                    if ($status === Purchase::RECEIVED) {
                        $this->adjustWarehouseStock(
                            $tenantId,
                            $mainWh->id,
                            $pi['product'],
                            +$pi['qty'],
                            'purchase',
                            Purchase::class,
                            $purchase->id,
                            'Purchase received ' . $refCode,
                            $manager->id,
                            $date
                        );
                    }
                }
            }
        }

        // 7. Sales & POS History (40 per branch = 120 total across 3 branches)
        $existingSales = Sale::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->count();
        if ($existingSales === 0) {
            for ($s = 0; $s < 40; $s++) {
                $date = Carbon::now()->subDays(29 - (int) floor($s * 0.72))->setTime(9 + ($s % 11), ($s * 7) % 60);
                $customer = $customers[$s % count($customers)];
                $refCode = sprintf('SA-%s-%04d', $spec['code'], $s + 1);
                $isPartial = ($s % 9 === 0);
                $payType = $s % 3 === 0 ? $cashPmId : ($s % 3 === 1 ? $bkashPmId : $bankPmId);

                $selected = [];
                for ($k = 0; $k < 3; $k++) {
                    $cand = $products[($s * 4 + $k * 7) % count($products)];
                    $stockRow = ManageStock::withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenantId)
                        ->where('warehouse_id', $mainWh->id)
                        ->where('product_id', $cand->id)
                        ->first();
                    $avail = (int) ($stockRow?->quantity ?? 0);
                    if ($avail >= 3) {
                        $qty = min(rand(1, 3), $avail - 1);
                        if ($qty > 0 && ! isset($selected[$cand->id])) {
                            $selected[$cand->id] = ['product' => $cand, 'qty' => $qty];
                        }
                    }
                }

                if (empty($selected)) {
                    continue;
                }

                $subTotalSum = 0;
                foreach ($selected as &$sel) {
                    $sel['sub_total'] = round($sel['product']->product_price * $sel['qty'], 2);
                    $subTotalSum += $sel['sub_total'];
                }
                unset($sel);

                $discount = $s % 5 === 0 ? min(30, round($subTotalSum * 0.05, 2)) : 0;
                $taxRate = 0;
                $taxAmount = 0;
                $grandTotal = round($subTotalSum - $discount + $taxAmount, 2);
                $paidAmount = $isPartial ? round($grandTotal * 0.6, 2) : $grandTotal;
                $paymentStatus = $isPartial ? Sale::PARTIAL_PAID : Sale::PAID;

                $sale = Sale::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $tenantId,
                    'date' => $date->toDateString(),
                    'is_return' => 0,
                    'customer_id' => $customer->id,
                    'warehouse_id' => $mainWh->id,
                    'tax_rate' => $taxRate,
                    'tax_amount' => $taxAmount,
                    'discount' => $discount,
                    'discount_type' => Sale::FIXED,
                    'discount_value' => $discount,
                    'shipping' => 0,
                    'grand_total' => $grandTotal,
                    'received_amount' => $paidAmount,
                    'paid_amount' => $paidAmount,
                    'payment_type' => $payType,
                    'note' => 'FreshMart POS Sale',
                    'reference_code' => $refCode,
                    'status' => Sale::COMPLETED,
                    'payment_status' => $paymentStatus,
                    'user_id' => $cashier->id,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);

                foreach ($selected as $sel) {
                    SaleItem::create([
                        'sale_id' => $sale->id,
                        'product_id' => $sel['product']->id,
                        'product_price' => $sel['product']->product_price,
                        'net_unit_price' => $sel['product']->product_price,
                        'tax_type' => Sale::EXCLUSIVE,
                        'tax_value' => 0,
                        'tax_amount' => 0,
                        'discount_type' => Sale::FIXED,
                        'discount_value' => 0,
                        'discount_amount' => 0,
                        'sale_unit' => (int) $sel['product']->sale_unit,
                        'quantity' => $sel['qty'],
                        'sub_total' => $sel['sub_total'],
                    ]);

                    $this->adjustWarehouseStock(
                        $tenantId,
                        $mainWh->id,
                        $sel['product'],
                        -$sel['qty'],
                        'sale',
                        Sale::class,
                        $sale->id,
                        'POS Sale ' . $refCode,
                        $cashier->id,
                        $date
                    );
                }

                SalesPayment::create([
                    'sale_id' => $sale->id,
                    'reference' => 'PAY-' . $refCode,
                    'payment_date' => $date->toDateString(),
                    'payment_type' => $payType,
                    'amount' => $paidAmount,
                    'received_amount' => $paidAmount,
                    'created_at' => $date,
                    'updated_at' => $date,
                ]);

                // Create 2 realistic Sales Returns per branch (6 total)
                if ($s === 5 || $s === 15) {
                    $firstItem = reset($selected);
                    $retQty = 1;
                    $retTotal = round($firstItem['product']->product_price * $retQty, 2);
                    $sale->update(['is_return' => 1]);
                    $saleReturn = SaleReturn::withoutGlobalScope('tenant')->create([
                        'tenant_id' => $tenantId,
                        'date' => $date->copy()->addDay()->toDateString(),
                        'customer_id' => $customer->id,
                        'warehouse_id' => $mainWh->id,
                        'sale_id' => $sale->id,
                        'tax_rate' => 0,
                        'tax_amount' => 0,
                        'discount' => 0,
                        'shipping' => 0,
                        'grand_total' => $retTotal,
                        'paid_amount' => $retTotal,
                        'payment_type' => $cashPmId,
                        'status' => SaleReturn::RECEIVED,
                        'note' => 'Customer returned unopened item',
                        'reference_code' => 'SR-' . $spec['code'] . '-' . $s,
                    ]);
                    SaleReturnItem::create([
                        'sale_return_id' => $saleReturn->id,
                        'product_id' => $firstItem['product']->id,
                        'product_price' => $firstItem['product']->product_price,
                        'net_unit_price' => $firstItem['product']->product_price,
                        'tax_type' => Sale::EXCLUSIVE,
                        'tax_value' => 0,
                        'tax_amount' => 0,
                        'discount_type' => Sale::FIXED,
                        'discount_value' => 0,
                        'discount_amount' => 0,
                        'sale_unit' => (int) $firstItem['product']->sale_unit,
                        'quantity' => $retQty,
                        'sold_quantity' => $firstItem['qty'],
                        'sub_total' => $retTotal,
                    ]);
                    $this->adjustWarehouseStock(
                        $tenantId,
                        $mainWh->id,
                        $firstItem['product'],
                        +$retQty,
                        'sale_return',
                        SaleReturn::class,
                        $saleReturn->id,
                        'Sale Return SR-' . $spec['code'] . '-' . $s,
                        $cashier->id,
                        $date->copy()->addDay()
                    );
                }
            }
        }

        // 8. Intra-Branch Warehouse Transfers (3 per branch = 9 total: Completed, Sent, Pending)
        $existingTransfers = Transfer::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->count();
        if ($existingTransfers === 0) {
            $transferStatuses = [Transfer::COMPLETED, Transfer::SENT, Transfer::PENDING];
            foreach ($transferStatuses as $tIdx => $tStatus) {
                $tDate = Carbon::now()->subDays(10 - $tIdx * 3);
                $prod = $products[($tIdx * 6) % count($products)];
                $qty = 10;
                $sub = round($prod->product_price * $qty, 2);
                $ref = sprintf('TR-%s-%03d', $spec['code'], $tIdx + 1);

                $transfer = Transfer::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $tenantId,
                    'date' => $tDate->toDateString(),
                    'from_warehouse_id' => $mainWh->id,
                    'to_warehouse_id' => $floorWh->id,
                    'tax_rate' => 0,
                    'tax_amount' => 0,
                    'discount' => 0,
                    'shipping' => 0,
                    'grand_total' => $sub,
                    'status' => $tStatus,
                    'note' => 'Replenishment from ' . $mainWh->name . ' to ' . $floorWh->name,
                    'reference_code' => $ref,
                ]);

                TransferItem::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $prod->id,
                    'product_price' => $prod->product_price,
                    'net_unit_price' => $prod->product_price,
                    'tax_type' => Transfer::EXCLUSIVE,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => Transfer::FIXED,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'quantity' => $qty,
                    'sub_total' => $sub,
                ]);

                if ($tStatus === Transfer::COMPLETED) {
                    $this->adjustWarehouseStock($tenantId, $mainWh->id, $prod, -$qty, 'transfer_out', Transfer::class, $transfer->id, 'Transfer out ' . $ref, $inventoryOfficer->id, $tDate);
                    $this->adjustWarehouseStock($tenantId, $floorWh->id, $prod, +$qty, 'transfer_in', Transfer::class, $transfer->id, 'Transfer in ' . $ref, $inventoryOfficer->id, $tDate);
                } elseif ($tStatus === Transfer::SENT) {
                    $this->adjustWarehouseStock($tenantId, $mainWh->id, $prod, -$qty, 'transfer_out', Transfer::class, $transfer->id, 'In-transit transfer out ' . $ref, $inventoryOfficer->id, $tDate);
                }
            }
        }

        // 9. Stock Adjustments (Damage & Stock Corrections: 3 per branch = 9 total)
        $existingAdj = Adjustment::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->count();
        if ($existingAdj === 0) {
            $adjSpecs = [
                ['prodIdx' => 54, 'qty' => 3, 'method' => AdjustmentItem::METHOD_SUBTRACTION, 'note' => 'Damaged milk/egg packs removed'],
                ['prodIdx' => 108, 'qty' => 4, 'method' => AdjustmentItem::METHOD_SUBTRACTION, 'note' => 'Near-expiry fresh produce shrinkage'],
                ['prodIdx' => 2, 'qty' => 5, 'method' => AdjustmentItem::METHOD_ADDITION, 'note' => 'Physical stock count reconciliation (+5)'],
            ];
            foreach ($adjSpecs as $aIdx => $aSpec) {
                $aDate = Carbon::now()->subDays(7 - $aIdx * 2);
                $prod = $products[$aSpec['prodIdx'] % count($products)];
                $ref = sprintf('AD-%s-%03d', $spec['code'], $aIdx + 1);
                $adj = Adjustment::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $tenantId,
                    'date' => $aDate->toDateString(),
                    'reference_code' => $ref,
                    'warehouse_id' => $mainWh->id,
                    'total_products' => 1,
                ]);
                AdjustmentItem::create([
                    'adjustment_id' => $adj->id,
                    'product_id' => $prod->id,
                    'quantity' => $aSpec['qty'],
                    'method_type' => $aSpec['method'],
                ]);
                $delta = $aSpec['method'] === AdjustmentItem::METHOD_ADDITION ? +$aSpec['qty'] : -$aSpec['qty'];
                $this->adjustWarehouseStock($tenantId, $mainWh->id, $prod, $delta, 'adjustment', Adjustment::class, $adj->id, $aSpec['note'], $inventoryOfficer->id, $aDate);
            }
        }

        // 10. Branch Expenses (6 categories, 12 expenses per branch = 36 total)
        $expCats = [];
        foreach ($expenseCategoriesList as $ecName) {
            $expCats[$ecName] = ExpenseCategory::withoutGlobalScope('tenant')->firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => $ecName],
                ['description' => $ecName . ' branch operational expense']
            );
        }
        $existingExpenses = Expense::withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->count();
        if ($existingExpenses === 0) {
            $baseRent = $branchKey === 'central' ? 45000 : ($branchKey === 'mirpur' ? 25000 : 22000);
            $baseSalaries = $branchKey === 'central' ? 85000 : ($branchKey === 'mirpur' ? 48000 : 44000);
            $expenseRows = [
                ['Rent', 'Monthly Shop & Warehouse Rent', $baseRent, 25],
                ['Electricity', 'DESCO/BPDB Monthly Electricity Bill', round($baseRent * 0.28), 22],
                ['Staff Salaries', 'Branch Staff Monthly Payroll', $baseSalaries, 20],
                ['Transport', 'Local Delivery Van & Fuel', 6500, 18],
                ['Packaging', 'Carry Bags, Thermal Rolls & Cartons', 4200, 15],
                ['Maintenance', 'Chiller & Freezer Servicing', 3800, 12],
                ['Transport', 'Wholesale Market Pickup', 4800, 10],
                ['Packaging', 'Barcode Labels & Poly Packs', 2400, 8],
                ['Electricity', 'Generator Diesel Backup', 3100, 6],
                ['Maintenance', 'POS Printer & Scanner Calibration', 1800, 4],
                ['Transport', 'Supplier Unloading Labor', 2200, 2],
                ['Packaging', 'Retail Eco Bags Restock', 2900, 1],
            ];
            foreach ($expenseRows as $eIdx => [$catName, $title, $amount, $daysAgo]) {
                $eDate = Carbon::now()->subDays($daysAgo);
                Expense::withoutGlobalScope('tenant')->create([
                    'tenant_id' => $tenantId,
                    'date' => $eDate->toDateString(),
                    'warehouse_id' => $mainWh->id,
                    'expense_category_id' => $expCats[$catName]->id,
                    'amount' => $amount,
                    'reference_code' => sprintf('EX-%s-%03d', $spec['code'], $eIdx + 1),
                    'title' => $title,
                    'details' => $title . ' — ' . $spec['name'],
                    'user_id' => $manager->id,
                    'created_at' => $eDate,
                    'updated_at' => $eDate,
                ]);
            }
        }
    }

    private function adjustWarehouseStock(
        string $tenantId,
        int $warehouseId,
        Product $product,
        int $delta,
        string $type,
        string $refType,
        int $refId,
        string $note,
        int $userId,
        Carbon $timestamp
    ): void {
        $stock = ManageStock::withoutGlobalScope('tenant')->firstOrCreate(
            ['tenant_id' => $tenantId, 'warehouse_id' => $warehouseId, 'product_id' => $product->id],
            ['quantity' => 0, 'alert' => 1]
        );
        $before = (float) $stock->quantity;
        $after = max(0, $before + $delta);
        $stock->update([
            'quantity' => $after,
            'alert' => $after <= (float) $product->stock_alert ? 1 : 0,
        ]);

        StockMovement::withoutGlobalScope('tenant')->create([
            'tenant_id' => $tenantId,
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'type' => $type,
            'quantity_change' => $after - $before,
            'quantity_before' => $before,
            'quantity_after' => $after,
            'unit_cost' => $product->product_cost,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'note' => $note,
            'user_id' => $userId,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function suppliersData(): array
    {
        return [
            ['name' => 'Kushtia Agro Rice Mills', 'email' => 'kushtiarice@supplier.demo', 'phone' => '01700-300001', 'city' => 'Kushtia', 'address' => 'Khajanagar Rice Belt, Kushtia'],
            ['name' => 'Meghna Edible Oil Distributors', 'email' => 'meghnaoil@supplier.demo', 'phone' => '01700-300002', 'city' => 'Dhaka', 'address' => 'Tejgaon Industrial Area, Dhaka'],
            ['name' => 'City Group Teer Depot', 'email' => 'teerdepot@supplier.demo', 'phone' => '01700-300003', 'city' => 'Kushtia', 'address' => 'Majampur Gate, Kushtia'],
            ['name' => 'ACI Consumer Brands Hub', 'email' => 'acibrands@supplier.demo', 'phone' => '01700-300004', 'city' => 'Jessore', 'address' => 'RN Road, Jessore'],
            ['name' => 'Pran-RFL Kushtia Distribution', 'email' => 'pranrfl@supplier.demo', 'phone' => '01700-300005', 'city' => 'Kushtia', 'address' => 'Chourhas Mor, Kushtia'],
            ['name' => 'Square Food & Spices Depot', 'email' => 'squarefood@supplier.demo', 'phone' => '01700-300006', 'city' => 'Pabna', 'address' => 'Ishwardi Road, Pabna'],
            ['name' => 'Aarong Dairy Cold Chain', 'email' => 'aarongdairy@supplier.demo', 'phone' => '01700-300007', 'city' => 'Kushtia', 'address' => 'Courtpara Cold Storage, Kushtia'],
            ['name' => 'Kazi Poultry & Eggs Hub', 'email' => 'kazieggs@supplier.demo', 'phone' => '01700-300008', 'city' => 'Kushtia', 'address' => 'Kumarkhali Agro Zone, Kushtia'],
            ['name' => 'Ispahani Tea Wholesale BD', 'email' => 'ispahani@supplier.demo', 'phone' => '01700-300009', 'city' => 'Khulna', 'address' => 'KDA Avenue, Khulna'],
            ['name' => 'Olympic Bakery & Biscuits Depot', 'email' => 'olympic@supplier.demo', 'phone' => '01700-300010', 'city' => 'Kushtia', 'address' => 'Thanapara, Kushtia'],
            ['name' => 'Padma Fresh Produce Traders', 'email' => 'padmafresh@supplier.demo', 'phone' => '01700-300011', 'city' => 'Bheramara', 'address' => 'Bheramara Wholesale Bazar'],
            ['name' => 'Unilever & Bashundhara Care Hub', 'email' => 'unicare@supplier.demo', 'phone' => '01700-300012', 'city' => 'Kushtia', 'address' => 'Nawab Sirajuddoula Road, Kushtia'],
        ];
    }

    /**
     * 18 Grocery Categories x 9 Realistic Bangladesh SKUs = 162 Products per branch.
     * Format: [Name, Brand, UnitKey, CostBDT, PriceBDT, CentralBaseStock, StockAlert, ExpiryDaysOrNull]
     */
    private function groceryCatalog(): array
    {
        return [
            'Rice & Grains' => [
                ['Miniket Rice 1kg', 'ACI Pure', '1kg', 66, 78, 250, 30, 180],
                ['Miniket Rice 5kg', 'ACI Pure', '5kg', 325, 380, 95, 15, 180],
                ['Nazirshail Rice 1kg', 'Teer', '1kg', 74, 88, 180, 25, 180],
                ['Nazirshail Rice 5kg', 'Teer', '5kg', 365, 430, 75, 12, 180],
                ['Chinigura Aromatic Rice 1kg', 'Pran', '1kg', 125, 150, 120, 20, 150],
                ['Basmati Rice 1kg', 'Fresh', '1kg', 185, 220, 65, 12, 180],
                ['BR-28 Rice 5kg', 'Local Fresh', '5kg', 295, 345, 110, 18, 180],
                ['Puffed Rice (Muri) 500g', 'Pran', '500g', 52, 65, 90, 15, 90],
                ['Flattened Rice (Chira) 500g', 'ACI Pure', '500g', 48, 60, 85, 15, 120],
            ],
            'Flour & Atta' => [
                ['Whole Wheat Atta 1kg', 'Teer', '1kg', 54, 65, 170, 25, 120],
                ['Whole Wheat Atta 2kg', 'Teer', '2kg', 105, 125, 130, 20, 120],
                ['Refined Maida 1kg', 'Fresh', '1kg', 62, 75, 140, 20, 120],
                ['Refined Maida 2kg', 'Fresh', '2kg', 120, 145, 90, 15, 120],
                ['Semolina (Suji) 500g', 'ACI Pure', '500g', 44, 55, 110, 15, 120],
                ['Besan (Gram Flour) 500g', 'Radhuni', '500g', 78, 95, 85, 15, 120],
                ['Rice Flour (Chaler Gura) 500g', 'ACI Pure', '500g', 55, 68, 70, 12, 120],
                ['Corn Flour 250g', 'Pran', '250g', 45, 58, 60, 10, 180],
                ['Brown Atta 1kg', 'Bashundhara', '1kg', 64, 78, 75, 12, 90],
            ],
            'Pulses & Lentils' => [
                ['Red Lentils (Moshur Dal) 1kg', 'ACI Pure', '1kg', 128, 148, 140, 25, 180],
                ['Red Lentils (Moshur Dal) 500g', 'ACI Pure', '500g', 66, 78, 110, 20, 180],
                ['Mung Dal 500g', 'Teer', '500g', 82, 98, 90, 15, 180],
                ['Chickpeas (Chola) 1kg', 'Fresh', '1kg', 96, 115, 120, 20, 180],
                ['Boot Dal 500g', 'Local Fresh', '500g', 58, 72, 85, 15, 180],
                ['Khesari Dal 1kg', 'Local Fresh', '1kg', 92, 110, 95, 15, 180],
                ['Anchor Dal 1kg', 'Local Fresh', '1kg', 78, 95, 100, 15, 180],
                ['Mashkalai Dal 500g', 'ACI Pure', '500g', 88, 105, 65, 12, 180],
                ['Green Peas (Motor) 500g', 'Fresh', '500g', 64, 78, 70, 12, 180],
            ],
            'Cooking Oil' => [
                ['Soybean Oil 1L', 'Rupchanda', '1L', 162, 178, 180, 30, 240],
                ['Soybean Oil 2L', 'Rupchanda', '2L', 322, 352, 120, 20, 240],
                ['Soybean Oil 5L', 'Teer', '5kg', 795, 865, 65, 10, 240],
                ['Mustard Oil 500ml', 'Radhuni', '500ml', 145, 175, 95, 15, 240],
                ['Mustard Oil 1L', 'ACI Pure', '1L', 280, 330, 70, 12, 240],
                ['Rice Bran Oil 1L', 'Fresh', '1L', 195, 225, 60, 10, 240],
                ['Sunflower Oil 1L', 'Rupchanda', '1L', 255, 295, 45, 8, 240],
                ['Pure Ghee 250g', 'Aarong Dairy', '250g', 360, 420, 50, 10, 150],
                ['Olive Oil 500ml', 'Fresh', '500ml', 540, 640, 30, 6, 300],
            ],
            'Salt & Sugar' => [
                ['Refined Sugar 1kg', 'Fresh', '1kg', 124, 140, 160, 25, 365],
                ['Refined Sugar 2kg', 'Teer', '2kg', 246, 275, 95, 15, 365],
                ['Iodized Salt 1kg', 'ACI Pure', '1kg', 34, 42, 200, 30, 365],
                ['Vacuum Salt 1kg', 'Fresh', '1kg', 35, 42, 150, 25, 365],
                ['Brown Sugar 500g', 'Pran', '500g', 78, 95, 60, 10, 365],
                ['Date Jaggery (Khejur Gur) 500g', 'Local Fresh', '500g', 140, 175, 45, 10, 60],
                ['Black Salt (Bit Lobon) 250g', 'Radhuni', '250g', 42, 55, 65, 10, 365],
                ['Rock Salt 500g', 'ACI Pure', '500g', 55, 70, 50, 10, 365],
                ['Sugar Cubes 500g', 'Fresh', '500g', 92, 115, 40, 8, 365],
            ],
            'Spices & Masala' => [
                ['Turmeric Powder (Holud) 250g', 'Radhuni', '250g', 95, 118, 110, 18, 180],
                ['Chili Powder (Morich) 250g', 'Radhuni', '250g', 125, 155, 105, 18, 180],
                ['Coriander Powder (Dhonia) 250g', 'ACI Pure', '250g', 78, 98, 95, 15, 180],
                ['Cumin Powder (Jira) 250g', 'Radhuni', '250g', 240, 290, 70, 12, 180],
                ['Biryani Masala 250g', 'Radhuni', '250g', 110, 138, 85, 15, 180],
                ['Garam Masala 250g', 'Pran', '250g', 135, 165, 75, 12, 180],
                ['Beef Curry Masala 250g', 'Radhuni', '250g', 115, 142, 80, 12, 180],
                ['Whole Cumin Seeds 250g', 'Local Fresh', '250g', 225, 270, 65, 12, 240],
                ['Bay Leaf & Cinnamon Pack 250g', 'Local Fresh', '250g', 95, 120, 60, 10, 240],
            ],
            'Dairy Products' => [
                ['Full Cream Milk Powder 500g', 'Marks', '500g', 375, 435, 95, 15, 180],
                ['Full Cream Milk Powder 1kg', 'Marks', '1kg', 740, 850, 55, 10, 180],
                ['Liquid Pasteurized Milk 1L', 'Aarong Dairy', '1L', 82, 95, 80, 18, 4],
                ['UHT Liquid Milk 1L', 'Pran', '1L', 90, 105, 110, 20, 90],
                ['Sweet Curd (Mishti Doi) 500g', 'Aarong Dairy', '500g', 145, 175, 45, 10, 5],
                ['Sour Curd (Tok Doi) 500g', 'Aarong Dairy', '500g', 115, 140, 40, 10, 6],
                ['Salted Butter 250g', 'Aarong Dairy', '250g', 235, 280, 50, 10, 45],
                ['Condensed Milk 500g', 'Fresh', '500g', 92, 110, 90, 15, 150],
                ['Cheddar Cheese Slice Pack', 'Aarong Dairy', 'Piece', 210, 255, 38, 8, 14],
            ],
            'Eggs' => [
                ['Farm Brown Eggs 12 pcs', 'Kazi Farms', 'Dozen', 142, 160, 75, 15, 10],
                ['Farm White Eggs 12 pcs', 'Kazi Farms', 'Dozen', 138, 155, 65, 15, 10],
                ['Organic Omega-3 Eggs 12 pcs', 'Kazi Farms', 'Dozen', 195, 225, 40, 10, 10],
                ['Duck Eggs 12 pcs', 'Local Fresh', 'Dozen', 190, 220, 35, 8, 9],
                ['Deshi Chicken Eggs 12 pcs', 'Local Fresh', 'Dozen', 210, 245, 30, 8, 9],
                ['Quail Eggs 12 pcs', 'Local Fresh', 'Dozen', 52, 68, 45, 10, 8],
                ['Farm Brown Eggs 6 pcs Pack', 'Kazi Farms', 'Piece', 74, 85, 60, 12, 10],
                ['Farm Eggs Bulk Tray (30 pcs)', 'Kazi Farms', 'Carton', 345, 390, 35, 8, 10],
                ['Salted Duck Eggs 6 pcs', 'Local Fresh', 'Piece', 110, 135, 25, 6, 15],
            ],
            'Beverages' => [
                ['Mirzapore Tea 200g', 'Ispahani', '250g', 108, 130, 120, 20, 240],
                ['Mirzapore Tea 500g', 'Ispahani', '500g', 255, 305, 80, 15, 240],
                ['Instant Coffee 250g', 'Nestle BD', '250g', 440, 520, 50, 10, 300],
                ['Mango Fruit Drink 1L', 'Pran', '1L', 78, 95, 110, 20, 120],
                ['Mineral Water 1L', 'Fresh', '1L', 18, 25, 220, 35, 365],
                ['Mineral Water 2L', 'Fresh', '2L', 28, 38, 150, 25, 365],
                ['Cola Soft Drink 1L', 'Pran', '1L', 58, 72, 130, 20, 150],
                ['Lemonade Drink 500ml', 'Pran', '500ml', 28, 35, 140, 25, 150],
                ['Green Tea 25 Bags', 'Ispahani', 'Piece', 98, 125, 65, 12, 240],
            ],
            'Snacks & Biscuits' => [
                ['Energy Plus Biscuit 200g', 'Olympic', '250g', 32, 42, 160, 25, 150],
                ['Tip Salted Biscuit 200g', 'Olympic', '250g', 35, 45, 140, 25, 150],
                ['Dry Cake Toast 250g', 'Pran', '250g', 75, 95, 100, 18, 90],
                ['Potato Crackers 250g', 'Pran', '250g', 48, 62, 120, 20, 120],
                ['Chanachur Classic 500g', 'Pran', '500g', 95, 120, 110, 18, 120],
                ['Lexux Cream Biscuit 250g', 'Olympic', '250g', 62, 78, 95, 15, 150],
                ['Roasted Peanuts 250g', 'Local Fresh', '250g', 75, 95, 70, 12, 90],
                ['Chocolate Wafer Pack', 'Olympic', 'Piece', 42, 55, 110, 18, 150],
                ['Cream Cracker Biscuit 250g', 'Bashundhara', '250g', 58, 72, 85, 15, 150],
            ],
            'Noodles & Pasta' => [
                ['Instant Noodles 8-Pack', 'Nestle BD', 'Carton', 142, 168, 130, 20, 180],
                ['Mr. Noodles Magic 8-Pack', 'Pran', 'Carton', 128, 155, 140, 20, 180],
                ['Stick Noodles 250g', 'ACI Pure', '250g', 38, 48, 110, 18, 180],
                ['Cup Noodles Masala', 'Pran', 'Piece', 42, 55, 95, 15, 180],
                ['Macaroni Pasta 500g', 'Bashundhara', '500g', 85, 105, 85, 15, 240],
                ['Penne Pasta 500g', 'Fresh', '500g', 92, 115, 70, 12, 240],
                ['Spaghetti 500g', 'ACI Pure', '500g', 95, 118, 65, 12, 240],
                ['Lachcha Semai 250g', 'Pran', '250g', 42, 55, 100, 15, 150],
                ['Vermicelli Semai 250g', 'Bashundhara', '250g', 38, 48, 90, 15, 150],
            ],
            'Frozen Foods' => [
                ['Frozen Chicken Nuggets 500g', 'Kazi Farms', '500g', 285, 340, 55, 10, 60],
                ['Frozen Chicken Sausages 500g', 'Kazi Farms', '500g', 265, 315, 50, 10, 60],
                ['Frozen Paratha 10 pcs', 'Kazi Farms', 'Carton', 145, 175, 80, 15, 45],
                ['Frozen Dal Puri 10 pcs', 'Pran', 'Carton', 125, 155, 65, 12, 45],
                ['Frozen Samosa 10 pcs', 'Kazi Farms', 'Carton', 135, 165, 60, 12, 45],
                ['Frozen French Fries 500g', 'Pran', '500g', 140, 170, 70, 12, 90],
                ['Frozen Chicken Strips 500g', 'Kazi Farms', '500g', 310, 365, 45, 10, 60],
                ['Frozen Green Peas 500g', 'Pran', '500g', 115, 140, 55, 10, 90],
                ['Frozen Fish Fingers 500g', 'Kazi Farms', '500g', 295, 350, 40, 8, 45],
            ],
            'Fruits' => [
                ['Red Apples 1kg', 'Local Fresh', '1kg', 240, 290, 60, 12, 7],
                ['Malta Oranges 1kg', 'Local Fresh', '1kg', 210, 255, 55, 12, 7],
                ['Sagor Banana 12 pcs', 'Local Fresh', 'Dozen', 95, 120, 70, 15, 4],
                ['Fresh Guava (Peyara) 1kg', 'Local Fresh', '1kg', 65, 85, 65, 12, 5],
                ['Ripe Papaya 1kg', 'Local Fresh', '1kg', 78, 98, 50, 10, 4],
                ['Green Grapes 500g', 'Local Fresh', '500g', 155, 190, 40, 8, 5],
                ['Pomegranate (Anar) 1kg', 'Local Fresh', '1kg', 290, 350, 35, 8, 7],
                ['Sweet Dates (Khejur) 500g', 'Fresh', '500g', 245, 295, 75, 12, 120],
                ['Fresh Lemon 12 pcs', 'Local Fresh', 'Dozen', 55, 75, 80, 15, 6],
            ],
            'Vegetables' => [
                ['Potato (Alu) 1kg', 'Local Fresh', '1kg', 38, 48, 220, 35, 14],
                ['Red Onion (Peyaj) 1kg', 'Local Fresh', '1kg', 82, 98, 190, 30, 14],
                ['Garlic (Roshun) 500g', 'Local Fresh', '500g', 105, 128, 110, 20, 20],
                ['Ginger (Ada) 500g', 'Local Fresh', '500g', 110, 135, 95, 18, 15],
                ['Fresh Green Chili 250g', 'Local Fresh', '250g', 35, 48, 85, 18, 4],
                ['Fresh Tomato 1kg', 'Local Fresh', '1kg', 62, 78, 90, 18, 5],
                ['Cucumber (Shosha) 1kg', 'Local Fresh', '1kg', 42, 55, 75, 15, 5],
                ['Carrot (Gajor) 1kg', 'Local Fresh', '1kg', 68, 85, 65, 12, 7],
                ['Eggplant (Begun) 1kg', 'Local Fresh', '1kg', 52, 68, 70, 15, 5],
            ],
            'Personal Care' => [
                ['Beauty Bath Soap 150g', 'Unilever BD', 'Piece', 52, 65, 150, 25, 365],
                ['Anti-Dandruff Shampoo 500ml', 'Unilever BD', '500ml', 360, 430, 65, 12, 365],
                ['Herbal Toothpaste 200g', 'Square', '250g', 95, 118, 110, 18, 365],
                ['Soft Toothbrush Pack', 'Square', 'Piece', 42, 55, 120, 20, null],
                ['Coconut Hair Oil 500ml', 'Square', '500ml', 245, 295, 80, 15, 365],
                ['Daily Face Wash 150ml', 'Unilever BD', 'Piece', 185, 225, 60, 12, 365],
                ['Moisturizing Body Lotion 500ml', 'Unilever BD', '500ml', 380, 455, 50, 10, 365],
                ['Handwash Liquid Refill 500ml', 'ACI Pure', '500ml', 115, 145, 95, 18, 365],
                ['Talcum Powder 250g', 'Square', '250g', 135, 165, 60, 12, 365],
            ],
            'Household Cleaning' => [
                ['Detergent Powder 1kg', 'Unilever BD', '1kg', 142, 170, 130, 20, 365],
                ['Dishwash Liquid 500ml', 'Unilever BD', '500ml', 118, 145, 110, 18, 365],
                ['Toilet Cleaner 500ml', 'ACI Pure', '500ml', 105, 130, 95, 15, 365],
                ['Floor Cleaner Disinfectant 1L', 'ACI Pure', '1L', 185, 225, 80, 15, 365],
                ['Glass Cleaner Spray 500ml', 'ACI Pure', '500ml', 135, 165, 65, 12, 365],
                ['Facial Tissue Box', 'Bashundhara', 'Piece', 58, 72, 140, 25, null],
                ['Kitchen Towel Roll', 'Bashundhara', 'Piece', 65, 82, 110, 20, null],
                ['Mosquito Aerosol Spray 500ml', 'ACI Pure', '500ml', 285, 340, 75, 15, 365],
                ['Garbage Bags Roll (20 pcs)', 'Bashundhara', 'Piece', 72, 92, 90, 15, null],
            ],
            'Baby Products' => [
                ['Baby Diapers Medium Pack', 'Bashundhara', 'Carton', 480, 560, 60, 12, 365],
                ['Baby Diapers Large Pack', 'Bashundhara', 'Carton', 510, 595, 55, 12, 365],
                ['Baby Wet Wipes (80 pcs)', 'Square', 'Piece', 145, 178, 85, 15, 240],
                ['Baby Milk Cereal 500g', 'Nestle BD', '500g', 420, 490, 65, 12, 180],
                ['Baby Gentle Soap 100g', 'Square', 'Piece', 68, 85, 90, 15, 365],
                ['Baby Lotion 250ml', 'Square', 'Piece', 210, 255, 55, 10, 365],
                ['Baby Shampoo 250ml', 'Square', 'Piece', 195, 238, 50, 10, 365],
                ['Baby Olive Oil 250ml', 'Square', 'Piece', 240, 290, 45, 10, 365],
                ['Baby Powder 250g', 'Square', '250g', 145, 175, 60, 12, 365],
            ],
            'Bakery Products' => [
                ['White Sandwich Bread 500g', 'Fresh', '500g', 52, 65, 75, 15, 4],
                ['Brown Wholemeal Bread 500g', 'Fresh', '500g', 62, 78, 55, 12, 4],
                ['Milk Bun 4 pcs Pack', 'Pran', 'Piece', 42, 55, 65, 15, 4],
                ['Plain Pound Cake 250g', 'Olympic', '250g', 95, 118, 70, 12, 12],
                ['Chocolate Muffin 4 pcs', 'Pran', 'Piece', 88, 110, 60, 12, 10],
                ['Butter Croissant 2 pcs', 'Fresh', 'Piece', 68, 85, 45, 10, 5],
                ['Rusk Toast 250g', 'Olympic', '250g', 55, 70, 90, 15, 60],
                ['Burger Buns 4 pcs', 'Fresh', 'Piece', 52, 68, 50, 10, 4],
                ['Fruit Cake Slice Pack', 'Olympic', 'Piece', 72, 90, 65, 12, 14],
            ],
        ];
    }
}
