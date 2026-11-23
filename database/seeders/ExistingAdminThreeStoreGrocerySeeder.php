<?php

namespace Database\Seeders;

use App\Models\AdjustmentItem;
use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\AdjustmentRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PurchaseReturnRepository;
use App\Repositories\SaleRepository;
use App\Repositories\SaleReturnRepository;
use App\Repositories\StoreRepository;
use App\Repositories\TransferRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExistingAdminThreeStoreGrocerySeeder extends Seeder
{
    private const ADMIN_ID = 2;
    private const STORE_1_ID = 1;
    private const STORE_2_ID = 387;

    public function run(): void
    {
        // Re-enable Eloquent mass-assignment guards so repositories ($model->update($input))
        // filter nested item arrays identically to live HTTP API requests.
        Model::reguard();

        $admin = User::findOrFail(self::ADMIN_ID);
        if ($admin->email !== 'admin@stockmate.com') {
            throw new \RuntimeException('Safety check failed: User ID 2 is not admin@stockmate.com.');
        }

        $store1 = Store::where('id', self::STORE_1_ID)->where('user_id', self::ADMIN_ID)->firstOrFail();

        // Idempotency check: skip if all 3 stores already have seeded sales & purchases
        $adminStores = Store::where('user_id', self::ADMIN_ID)->orderBy('id')->get();
        if ($adminStores->count() === 3) {
            $allDone = true;
            foreach ($adminStores as $as) {
                if (
                    ! Product::withoutGlobalScopes()->where('tenant_id', $as->tenant_id)->exists() ||
                    ! Sale::withoutGlobalScopes()->where('tenant_id', $as->tenant_id)->exists()
                ) {
                    $allDone = false;
                    break;
                }
            }
            if ($allDone) {
                $this->command?->info('ExistingAdminThreeStoreGrocerySeeder: All 3 stores under admin@stockmate.com already seeded — skipping to preserve idempotency.');
                return;
            }
        }

        $bdtCurrencyId = (string) (Currency::where('code', 'BDT')->value('id') ?? 3);

        Auth::login($admin);

        // 1. Configure exactly 3 stores under admin@stockmate.com (ID 2)
        $store1->update(['name' => 'FreshMart Central – Main Branch, Kushtia']);

        $store2 = Store::where('id', self::STORE_2_ID)->where('user_id', self::ADMIN_ID)->firstOrFail();
        $store2->update(['name' => 'FreshMart Bheramara – Bheramara, Kushtia']);

        $existingStores = Store::where('user_id', self::ADMIN_ID)->orderBy('id')->get();
        if ($existingStores->count() >= 3) {
            $store3 = $existingStores->get(2);
            $store3->update(['name' => 'FreshMart Mirpur – Mirpur, Kushtia']);
        } else {
            /** @var StoreRepository $storeRepo */
            $storeRepo = app(StoreRepository::class);
            $store3 = $storeRepo->store(['name' => 'FreshMart Mirpur – Mirpur, Kushtia']);
        }

        $branches = [
            [
                'store' => $store1,
                'prefix' => 'FMC-A',
                'short' => 'Central',
                'city' => 'Kushtia Sadar',
                'address' => 'NS Road, Kushtia Sadar, Kushtia - 7000',
                'phone' => '01749-560393',
                'stock_scale' => 1.3,
                'sales_count' => 25,
                'expense_scale' => 1.25,
            ],
            [
                'store' => $store2,
                'prefix' => 'FMB-A',
                'short' => 'Bheramara',
                'city' => 'Bheramara',
                'address' => 'Station Road, Bheramara, Kushtia - 7040',
                'phone' => '01931-336664',
                'stock_scale' => 0.9,
                'sales_count' => 22,
                'expense_scale' => 0.85,
            ],
            [
                'store' => $store3,
                'prefix' => 'FMM-A',
                'short' => 'Mirpur',
                'city' => 'Mirpur',
                'address' => 'Bazar Main Road, Mirpur, Kushtia - 7030',
                'phone' => '01749-560393',
                'stock_scale' => 1.0,
                'sales_count' => 22,
                'expense_scale' => 0.95,
            ],
        ];

        $catalog = $this->buildCatalog(); // 156 realistic Bangladesh grocery SKUs

        foreach ($branches as $bIndex => $cfg) {
            $this->seedBranch($admin, $cfg, $bdtCurrencyId, $catalog, $bIndex);
        }

        // Restore admin@stockmate.com active store to Store 1 (FreshMart Central)
        $admin->update(['tenant_id' => $store1->tenant_id]);
        Auth::login($admin->fresh());

        $this->command?->info('ExistingAdminThreeStoreGrocerySeeder: Successfully seeded 3 FreshMart grocery stores under admin@stockmate.com (ID 2).');
    }

    private function seedBranch(User $admin, array $cfg, string $bdtCurrencyId, array $catalog, int $bIndex): void
    {
        /** @var Store $store */
        $store = $cfg['store'];
        $tenantId = $store->tenant_id;

        // Switch authenticated admin to this store's tenant so all repositories & Multitenantable scopes bind cleanly
        $admin->update(['tenant_id' => $tenantId]);
        Auth::login($admin->fresh());

        // 1. Settings -> BDT currency & branch metadata
        $settingsMap = [
            'currency' => $bdtCurrencyId,
            'company_name' => $store->name,
            'store_name' => $store->name,
            'phone' => $cfg['phone'],
            'address' => $cfg['address'],
            'email' => 'dynamic.digital.ltd@gmail.com',
            'sale_code' => 'SA',
            'purchase_code' => 'PU',
            'purchase_return_code' => 'PR',
            'sale_return_code' => 'SR',
            'expense_code' => 'EX',
        ];
        foreach ($settingsMap as $key => $val) {
            Setting::withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'key' => $key],
                ['value' => $val]
            );
        }

        // 2. Payment Methods (Cash, bKash, Card/Bank)
        $pmCash = PaymentMethod::firstOrCreate(['name' => 'Cash'], ['status' => 1]);
        $pmBkash = PaymentMethod::firstOrCreate(['name' => 'bKash / Mobile Banking'], ['status' => 1]);
        $pmCard = PaymentMethod::firstOrCreate(['name' => 'Card / Bank Transfer'], ['status' => 1]);
        $paymentMethods = [$pmCash->id, $pmBkash->id, $pmCard->id];

        // 3. Warehouses (reuse existing default warehouse + create Retail Floor)
        $mainWh = Warehouse::orderBy('id')->first();
        if ($mainWh) {
            $mainWh->update([
                'name' => "{$cfg['short']} Main Warehouse",
                'city' => $cfg['city'],
                'country' => 'Bangladesh',
                'phone' => $cfg['phone'],
                'email' => strtolower($cfg['short']) . '.main@freshmart.local',
                'zip_code' => '7000',
            ]);
        } else {
            $mainWh = Warehouse::create([
                'name' => "{$cfg['short']} Main Warehouse",
                'city' => $cfg['city'],
                'country' => 'Bangladesh',
                'phone' => $cfg['phone'],
                'email' => strtolower($cfg['short']) . '.main@freshmart.local',
                'zip_code' => '7000',
            ]);
        }
        $floorWh = Warehouse::firstOrCreate(
            ['name' => "{$cfg['short']} Retail Floor"],
            [
                'city' => $cfg['city'],
                'country' => 'Bangladesh',
                'phone' => $cfg['phone'],
                'email' => strtolower($cfg['short']) . '.floor@freshmart.local',
                'zip_code' => '7000',
            ]
        );

        // 4. Base Units & Units (1kg, 500g, 250g, 5L, 2L, 1L, 1pc)
        $buKg = BaseUnit::firstOrCreate(['name' => 'kilogram'], ['is_default' => 1]);
        $buL = BaseUnit::firstOrCreate(['name' => 'liter'], ['is_default' => 0]);
        $buPc = BaseUnit::firstOrCreate(['name' => 'piece'], ['is_default' => 0]);

        $units = [
            '1kg' => Unit::firstOrCreate(['short_name' => '1kg'], ['name' => '1 Kilogram', 'base_unit' => $buKg->id]),
            '500g' => Unit::firstOrCreate(['short_name' => '500g'], ['name' => '500 Gram', 'base_unit' => $buKg->id]),
            '250g' => Unit::firstOrCreate(['short_name' => '250g'], ['name' => '250 Gram', 'base_unit' => $buKg->id]),
            '5L' => Unit::firstOrCreate(['short_name' => '5L'], ['name' => '5 Liter', 'base_unit' => $buL->id]),
            '2L' => Unit::firstOrCreate(['short_name' => '2L'], ['name' => '2 Liter', 'base_unit' => $buL->id]),
            '1L' => Unit::firstOrCreate(['short_name' => '1L'], ['name' => '1 Liter', 'base_unit' => $buL->id]),
            '1pc' => Unit::firstOrCreate(['short_name' => '1pc'], ['name' => '1 Piece', 'base_unit' => $buPc->id]),
        ];
        $baseByKey = [
            '1kg' => $buKg->id, '500g' => $buKg->id, '250g' => $buKg->id,
            '5L' => $buL->id, '2L' => $buL->id, '1L' => $buL->id,
            '1pc' => $buPc->id,
        ];

        // 5. Categories (10 requested grocery categories)
        $catNames = [
            'Rice & Grains', 'Cooking Oil', 'Spices', 'Beverages', 'Snacks',
            'Dairy', 'Frozen Food', 'Personal Care', 'Household Cleaning', 'Baby Products',
        ];
        $cats = [];
        foreach ($catNames as $cn) {
            $cats[$cn] = ProductCategory::firstOrCreate(['name' => $cn]);
        }

        // 6. Brands
        $brandNames = [
            'ACI Pure', 'Teer', 'Rupchanda', 'Pran', 'Radhuni', 'Fresh',
            'Aarong Dairy', 'Ispahani', 'Olympic', 'Bashundhara',
            'Kazi Farms', 'Unilever BD', 'Square Toiletries', 'Marks', 'Nestle BD',
        ];
        $brands = [];
        foreach ($brandNames as $bn) {
            $brands[$bn] = Brand::firstOrCreate(['name' => $bn]);
        }

        // 7. Suppliers (8 realistic Bangladesh distributors)
        $supplierDefs = [
            ['Kushtia Agro Rice Mills Ltd', '01711-220101', 'Kushtia'],
            ['City Group Edible Oil Distribution', '01711-220102', 'Dhaka'],
            ['ACI Consumer Brands Kushtia Depot', '01711-220103', 'Kushtia'],
            ['PRAN-RFL Regional Wholesalers', '01711-220104', 'Rajshahi'],
            ['Square Food & Beverage Agency', '01711-220105', 'Pabna'],
            ['Aarong & Brac Dairy Cold Chain', '01711-220106', 'Kushtia'],
            ['Unilever & Homecare Distributors BD', '01711-220107', 'Khulna'],
            ['Kazi Farms & Baby Care Supply House', '01711-220108', 'Jashore'],
        ];
        $suppliers = [];
        foreach ($supplierDefs as $idx => [$sname, $sphone, $scity]) {
            $suppliers[] = Supplier::firstOrCreate(
                ['email' => strtolower($cfg['short']) . ".sup{$idx}@freshmart.local"],
                [
                    'name' => $sname,
                    'phone' => $sphone,
                    'country' => 'Bangladesh',
                    'city' => $scity,
                    'address' => "Wholesale Market, {$scity}",
                ]
            );
        }

        // 8. Customers (preserve walk-in-customer + add 12 realistic local customers)
        $customerNames = [
            'Kamrul Islam', 'Nasrin Akter', 'Shafiqul Alam', 'Ruma Khatun',
            'Jahangir Hossain', 'Salma Begum', 'Tariqul Islam', 'Momena Khatun',
            'Rezaul Karim', 'Sadia Afrin', 'Ashraful Haque', 'Nargis Sultana',
        ];
        $customers = [];
        $walkIn = Customer::first();
        if ($walkIn) {
            $customers[] = $walkIn;
        }
        foreach ($customerNames as $idx => $cname) {
            $customers[] = Customer::firstOrCreate(
                ['email' => strtolower($cfg['short']) . ".cust{$idx}@freshmart.local"],
                [
                    'name' => $cname,
                    'phone' => '018' . str_pad((string) (20000000 + $bIndex * 1000 + $idx), 8, '0', STR_PAD_LEFT),
                    'country' => 'Bangladesh',
                    'city' => $cfg['city'],
                    'address' => "Ward " . (($idx % 9) + 1) . ", {$cfg['city']}",
                ]
            );
        }

        // 9. Products (156 SKUs per store) + Opening Stock (with 10 low-stock & 5 out-of-stock)
        $products = [];
        foreach ($catalog as $i => $item) {
            $seq = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $sku = "{$cfg['prefix']}-{$seq}";
            $barcode = '8942' . ($bIndex + 1) . str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT);
            $unitKey = $item['unit'];
            $unitObj = $units[$unitKey];
            $baseUnitId = $baseByKey[$unitKey];

            $cost = (float) $item['cost'];
            $price = (float) $item['price'];
            $stockAlert = (int) ($item['alert'] ?? 10);

            $expiry = in_array($item['cat'], ['Dairy', 'Frozen Food', 'Baby Products'], true)
                ? Carbon::today()->addDays(10 + ($i % 35))->format('Y-m-d')
                : null;

            $existingProd = Product::where('code', $sku)->first();
            if ($existingProd) {
                $products[] = $existingProd;
                continue;
            }

            $mainProd = MainProduct::create([
                'name' => $item['name'],
                'code' => $sku,
                'product_unit' => $baseUnitId,
                'product_type' => MainProduct::SINGLE_PRODUCT,
            ]);

            $prod = Product::create([
                'main_product_id' => $mainProd->id,
                'name' => $item['name'],
                'code' => $sku,
                'product_code' => $barcode,
                'product_category_id' => $cats[$item['cat']]->id,
                'brand_id' => $brands[$item['brand']]->id,
                'product_cost' => $cost,
                'product_price' => $price,
                'product_unit' => $baseUnitId,
                'sale_unit' => $unitObj->id,
                'purchase_unit' => $unitObj->id,
                'stock_alert' => $stockAlert,
                'quantity_limit' => 200,
                'order_tax' => 0,
                'tax_type' => 1,
                'barcode_symbol' => 1,
                'expiry_date' => $expiry,
                'notes' => "{$item['cat']} ({$unitKey}) — {$cfg['short']} Branch",
            ]);

            // Controlled opening stock:
            // Last 5 SKUs (indices 151..155) => Out of stock (0)
            // Previous 10 SKUs (indices 141..150) => Low stock (2..alert-1)
            // Remaining 141 SKUs => healthy opening stock scaled by branch
            if ($i >= 151) {
                $openingQty = 0;
            } elseif ($i >= 141) {
                $openingQty = max(2, min($stockAlert - 1, 4 + ($i % 4)));
            } else {
                $openingQty = (int) round((35 + (($i * 7) % 65)) * $cfg['stock_scale']);
            }

            if ($openingQty > 0) {
                manageStock(
                    $mainWh->id,
                    $prod->id,
                    $openingQty,
                    'opening_stock',
                    Product::class,
                    $prod->id,
                    $cost,
                    "Opening stock for {$cfg['short']}"
                );
            } else {
                ManageStock::create([
                    'warehouse_id' => $mainWh->id,
                    'product_id' => $prod->id,
                    'quantity' => 0,
                ]);
            }

            $products[] = $prod;
        }

        // 10. Purchases (skip transactional seeding if already populated for this branch)
        if (Purchase::count() > 0 && Sale::count() > 0) {
            return;
        }

        /** @var PurchaseRepository $purchaseRepo */
        $purchaseRepo = app(PurchaseRepository::class);
        $createdPurchases = [];
        for ($p = 0; $p < 10; $p++) {
            $sup = $suppliers[$p % count($suppliers)];
            $pDate = Carbon::today()->subDays(18 - $p)->format('Y-m-d');
            $pItems = [];
            $grand = 0;
            for ($k = 0; $k < 4; $k++) {
                $prod = $products[($p * 11 + $k * 3) % 120];
                $qty = 20 + (($p + $k) % 4) * 5;
                $sub = $qty * (float) $prod->product_cost;
                $grand += $sub;
                $pItems[] = [
                    'product_id' => $prod->id,
                    'product_cost' => (float) $prod->product_cost,
                    'net_unit_cost' => (float) $prod->product_cost,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'purchase_unit' => $prod->purchase_unit,
                    'quantity' => $qty,
                    'sub_total' => $sub,
                ];
            }
            $purchase = $purchaseRepo->storePurchase([
                'date' => $pDate,
                'supplier_id' => $sup->id,
                'warehouse_id' => $mainWh->id,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'discount' => 0,
                'shipping' => 0,
                'grand_total' => $grand,
                'received_amount' => $grand,
                'paid_amount' => $grand,
                'payment_type' => $pmCash->id,
                'status' => Purchase::RECEIVED,
                'notes' => "Supplier restock #{$p} — {$cfg['short']}",
                'purchase_items' => $pItems,
            ]);
            $createdPurchases[] = ['purchase' => $purchase, 'items' => $pItems, 'supplier_id' => $sup->id];
        }

        // 11. Purchase Return (1 per branch via PurchaseReturnRepository + movement log)
        /** @var PurchaseReturnRepository $purchaseReturnRepo */
        $purchaseReturnRepo = app(PurchaseReturnRepository::class);
        $firstPur = $createdPurchases[0];
        $retPurItem = $firstPur['items'][0];
        $retQty = 3;
        $retSub = $retQty * (float) $retPurItem['product_cost'];
        $beforePrQty = (float) ManageStock::whereWarehouseId($mainWh->id)->whereProductId($retPurItem['product_id'])->value('quantity');
        $pr = $purchaseReturnRepo->storePurchaseReturn([
            'date' => Carbon::today()->subDays(5)->format('Y-m-d'),
            'supplier_id' => $firstPur['supplier_id'],
            'warehouse_id' => $mainWh->id,
            'purchase_id' => $firstPur['purchase']->id,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => $retSub,
            'received_amount' => $retSub,
            'paid_amount' => $retSub,
            'payment_type' => $pmCash->id,
            'payment_status' => 1,
            'status' => 1,
            'notes' => "Damaged carton supplier return — {$cfg['short']}",
            'purchase_return_items' => [[
                'product_id' => $retPurItem['product_id'],
                'product_cost' => $retPurItem['product_cost'],
                'net_unit_cost' => $retPurItem['net_unit_cost'],
                'tax_type' => 1,
                'tax_value' => 0,
                'tax_amount' => 0,
                'discount_type' => 2,
                'discount_value' => 0,
                'discount_amount' => 0,
                'purchase_unit' => $retPurItem['purchase_unit'],
                'quantity' => $retQty,
                'sub_total' => $retSub,
            ]],
        ]);
        recordStockMovement(
            (int) $mainWh->id,
            (int) $retPurItem['product_id'],
            'purchase_return',
            -(float) $retQty,
            $beforePrQty,
            $beforePrQty - (float) $retQty,
            PurchaseReturn::class,
            $pr->id,
            (float) $retPurItem['product_cost'],
            'Supplier purchase return'
        );

        // 12. POS Sales (22–25 sales via SaleRepository -> deducts stock & logs sale movements)
        /** @var SaleRepository $saleRepo */
        $saleRepo = app(SaleRepository::class);
        $createdSales = [];
        for ($s = 0; $s < $cfg['sales_count']; $s++) {
            $cust = $customers[$s % count($customers)];
            $pmId = $paymentMethods[$s % count($paymentMethods)];
            $sDate = Carbon::today()->subDays(min(14, $cfg['sales_count'] - 1 - $s))->format('Y-m-d');
            $sItems = [];
            $grand = 0;
            for ($k = 0; $k < 3; $k++) {
                $prod = $products[($s * 5 + $k * 7) % 125];
                $qty = 1 + (($s + $k) % 3);
                $sub = $qty * (float) $prod->product_price;
                $grand += $sub;
                $sItems[] = [
                    'product_id' => $prod->id,
                    'product_price' => (float) $prod->product_price,
                    'net_unit_price' => (float) $prod->product_price,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $prod->sale_unit,
                    'quantity' => $qty,
                    'sub_total' => $sub,
                ];
            }
            $sale = $saleRepo->storeSale([
                'date' => $sDate,
                'customer_id' => $cust->id,
                'warehouse_id' => $mainWh->id,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'discount' => 0,
                'shipping' => 0,
                'grand_total' => $grand,
                'received_amount' => $grand,
                'paid_amount' => $grand,
                'payment_type' => $pmId,
                'payment_status' => Sale::PAID,
                'status' => Sale::COMPLETED,
                'is_sale_created' => true,
                'note' => "POS Checkout #{$s} — {$cfg['short']}",
                'payment_details' => [['payment_type' => ['value' => $pmId], 'amount' => $grand]],
                'sale_items' => $sItems,
            ]);
            $createdSales[] = ['sale' => $sale, 'items' => $sItems, 'customer_id' => $cust->id];
        }

        // 13. Sales Returns (2 per branch via SaleReturnRepository -> restores stock & logs sale_return)
        /** @var SaleReturnRepository $saleReturnRepo */
        $saleReturnRepo = app(SaleReturnRepository::class);
        for ($r = 0; $r < 2; $r++) {
            $target = $createdSales[$r * 3];
            $item = $target['items'][0];
            $rQty = 1;
            $rSub = $rQty * (float) $item['product_price'];
            $saleReturnRepo->storeSaleReturn([
                'date' => Carbon::today()->subDays(2 - $r)->format('Y-m-d'),
                'customer_id' => $target['customer_id'],
                'warehouse_id' => $mainWh->id,
                'sale_id' => $target['sale']->id,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'discount' => 0,
                'shipping' => 0,
                'grand_total' => $rSub,
                'paid_amount' => $rSub,
                'payment_type' => $pmCash->id,
                'payment_status' => 1,
                'status' => 1,
                'note' => "Customer return — {$cfg['short']}",
                'sale_return_items' => [[
                    'product_id' => $item['product_id'],
                    'product_price' => $item['product_price'],
                    'net_unit_price' => $item['net_unit_price'],
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $item['sale_unit'],
                    'quantity' => $rQty,
                    'sub_total' => $rSub,
                ]],
            ]);
        }

        // 14. Stock Adjustments (3 per branch via AdjustmentRepository -> logs adjustment_add / adjustment_sub)
        /** @var AdjustmentRepository $adjRepo */
        $adjRepo = app(AdjustmentRepository::class);
        $adjConfigs = [
            [$products[2], 3, AdjustmentItem::METHOD_SUBTRACTION, 4],
            [$products[8], 5, AdjustmentItem::METHOD_ADDITION, 3],
            [$products[14], 2, AdjustmentItem::METHOD_SUBTRACTION, 1],
        ];
        foreach ($adjConfigs as [$prod, $qty, $method, $daysAgo]) {
            $adjRepo->storeAdjustment([
                'date' => Carbon::today()->subDays($daysAgo)->format('Y-m-d'),
                'warehouse_id' => $mainWh->id,
                'adjustment_items' => [[
                    'product_id' => $prod->id,
                    'quantity' => $qty,
                    'method_type' => $method,
                ]],
            ]);
        }

        // 15. Intra-Branch Warehouse Transfers (3 per branch via TransferRepository -> logs transfer_out & transfer_in)
        /** @var TransferRepository $transferRepo */
        $transferRepo = app(TransferRepository::class);
        $trStatuses = [Transfer::COMPLETED, Transfer::SENT, Transfer::PENDING];
        for ($t = 0; $t < 3; $t++) {
            $prod = $products[10 + $t * 6];
            $tQty = 10;
            $tSub = $tQty * (float) $prod->product_price;
            $transferRepo->storeTransfer([
                'date' => Carbon::today()->subDays(5 - $t)->format('Y-m-d'),
                'from_warehouse_id' => $mainWh->id,
                'to_warehouse_id' => $floorWh->id,
                'tax_rate' => 0,
                'tax_amount' => 0,
                'discount' => 0,
                'shipping' => 0,
                'grand_total' => $tSub,
                'status' => $trStatuses[$t],
                'note' => "Shelf replenishment {$cfg['short']} Main -> Retail Floor",
                'transfer_items' => [[
                    'product_id' => $prod->id,
                    'product_price' => (float) $prod->product_price,
                    'net_unit_price' => (float) $prod->product_price,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'quantity' => $tQty,
                    'sub_total' => $tSub,
                ]],
            ]);
        }

        // 16. Expenses (10 realistic operating expenses across 5 categories)
        $expCatNames = ['Shop Rent', 'Electricity & Generator', 'Staff Salaries', 'Local Transport', 'Packaging & Bags'];
        $expCats = [];
        foreach ($expCatNames as $ec) {
            $expCats[$ec] = ExpenseCategory::firstOrCreate(['name' => $ec]);
        }
        $expenseDefs = [
            ['Shop Rent', 'Monthly Store Lease', 42000, 15],
            ['Staff Salaries', 'Branch Staff Payroll', 58000, 12],
            ['Electricity & Generator', 'PDB Electricity Bill', 11500, 10],
            ['Electricity & Generator', 'Generator Fuel & Maintenance', 4200, 8],
            ['Local Transport', 'Wholesale Van Delivery Charges', 3800, 7],
            ['Packaging & Bags', 'Poly & Shopping Bags Restock', 2900, 6],
            ['Local Transport', 'Rice & Oil Unloading Labor', 2400, 5],
            ['Packaging & Bags', 'Thermal POS Receipt Paper Rolls', 1600, 4],
            ['Electricity & Generator', 'Cold Chiller Servicing', 3500, 2],
            ['Local Transport', 'Urgent Market Restock Delivery', 1800, 1],
        ];
        foreach ($expenseDefs as [$catName, $title, $baseAmt, $daysAgo]) {
            Expense::create([
                'date' => Carbon::today()->subDays($daysAgo)->format('Y-m-d'),
                'warehouse_id' => $mainWh->id,
                'expense_category_id' => $expCats[$catName]->id,
                'amount' => round($baseAmt * $cfg['expense_scale'], 2),
                'title' => "{$title} ({$cfg['short']})",
                'details' => "Monthly operating expense — {$store->name}",
            ]);
        }
    }

    private function buildCatalog(): array
    {
        return [
            // 1. Rice & Grains (18)
            ['name' => 'Miniket Rice 1kg', 'cat' => 'Rice & Grains', 'brand' => 'ACI Pure', 'unit' => '1kg', 'cost' => 68, 'price' => 78, 'alert' => 15],
            ['name' => 'Miniket Rice 5kg', 'cat' => 'Rice & Grains', 'brand' => 'ACI Pure', 'unit' => '1kg', 'cost' => 335, 'price' => 380, 'alert' => 10],
            ['name' => 'Nazirshail Rice 1kg', 'cat' => 'Rice & Grains', 'brand' => 'Teer', 'unit' => '1kg', 'cost' => 76, 'price' => 88, 'alert' => 15],
            ['name' => 'Nazirshail Rice 5kg', 'cat' => 'Rice & Grains', 'brand' => 'Teer', 'unit' => '1kg', 'cost' => 375, 'price' => 430, 'alert' => 10],
            ['name' => 'Chinigura Aromatic Rice 1kg', 'cat' => 'Rice & Grains', 'brand' => 'Pran', 'unit' => '1kg', 'cost' => 128, 'price' => 150, 'alert' => 12],
            ['name' => 'Basmati Rice 1kg', 'cat' => 'Rice & Grains', 'brand' => 'Fresh', 'unit' => '1kg', 'cost' => 188, 'price' => 220, 'alert' => 10],
            ['name' => 'BR-28 Rice 5kg', 'cat' => 'Rice & Grains', 'brand' => 'Teer', 'unit' => '1kg', 'cost' => 298, 'price' => 345, 'alert' => 10],
            ['name' => 'Whole Wheat Atta 1kg', 'cat' => 'Rice & Grains', 'brand' => 'Teer', 'unit' => '1kg', 'cost' => 54, 'price' => 64, 'alert' => 15],
            ['name' => 'Whole Wheat Atta 2kg', 'cat' => 'Rice & Grains', 'brand' => 'Fresh', 'unit' => '1kg', 'cost' => 106, 'price' => 124, 'alert' => 12],
            ['name' => 'Refined Maida 1kg', 'cat' => 'Rice & Grains', 'brand' => 'ACI Pure', 'unit' => '1kg', 'cost' => 64, 'price' => 75, 'alert' => 12],
            ['name' => 'Semolina (Suji) 500g', 'cat' => 'Rice & Grains', 'brand' => 'ACI Pure', 'unit' => '500g', 'cost' => 46, 'price' => 55, 'alert' => 10],
            ['name' => 'Red Lentils (Moshur Dal) 1kg', 'cat' => 'Rice & Grains', 'brand' => 'Fresh', 'unit' => '1kg', 'cost' => 128, 'price' => 148, 'alert' => 15],
            ['name' => 'Red Lentils (Moshur Dal) 500g', 'cat' => 'Rice & Grains', 'brand' => 'ACI Pure', 'unit' => '500g', 'cost' => 66, 'price' => 78, 'alert' => 12],
            ['name' => 'Mung Dal 500g', 'cat' => 'Rice & Grains', 'brand' => 'Pran', 'unit' => '500g', 'cost' => 84, 'price' => 98, 'alert' => 10],
            ['name' => 'Chickpeas (Chola) 1kg', 'cat' => 'Rice & Grains', 'brand' => 'Fresh', 'unit' => '1kg', 'cost' => 98, 'price' => 115, 'alert' => 10],
            ['name' => 'Puffed Rice (Muri) 500g', 'cat' => 'Rice & Grains', 'brand' => 'Pran', 'unit' => '500g', 'cost' => 54, 'price' => 65, 'alert' => 12],
            ['name' => 'Flattened Rice (Chira) 500g', 'cat' => 'Rice & Grains', 'brand' => 'ACI Pure', 'unit' => '500g', 'cost' => 50, 'price' => 60, 'alert' => 10],
            ['name' => 'Fine White Sugar 1kg', 'cat' => 'Rice & Grains', 'brand' => 'Fresh', 'unit' => '1kg', 'cost' => 118, 'price' => 135, 'alert' => 15],

            // 2. Cooking Oil (14)
            ['name' => 'Soybean Oil 1L', 'cat' => 'Cooking Oil', 'brand' => 'Rupchanda', 'unit' => '1L', 'cost' => 156, 'price' => 172, 'alert' => 15],
            ['name' => 'Soybean Oil 2L', 'cat' => 'Cooking Oil', 'brand' => 'Rupchanda', 'unit' => '2L', 'cost' => 310, 'price' => 342, 'alert' => 12],
            ['name' => 'Soybean Oil 5L', 'cat' => 'Cooking Oil', 'brand' => 'Rupchanda', 'unit' => '5L', 'cost' => 770, 'price' => 845, 'alert' => 8],
            ['name' => 'Teer Soybean Oil 1L', 'cat' => 'Cooking Oil', 'brand' => 'Teer', 'unit' => '1L', 'cost' => 154, 'price' => 170, 'alert' => 15],
            ['name' => 'Teer Soybean Oil 5L', 'cat' => 'Cooking Oil', 'brand' => 'Teer', 'unit' => '5L', 'cost' => 760, 'price' => 835, 'alert' => 8],
            ['name' => 'Fresh Soybean Oil 2L', 'cat' => 'Cooking Oil', 'brand' => 'Fresh', 'unit' => '2L', 'cost' => 308, 'price' => 340, 'alert' => 10],
            ['name' => 'Mustard Oil 250g', 'cat' => 'Cooking Oil', 'brand' => 'Radhuni', 'unit' => '250g', 'cost' => 82, 'price' => 98, 'alert' => 12],
            ['name' => 'Mustard Oil 500g', 'cat' => 'Cooking Oil', 'brand' => 'Radhuni', 'unit' => '500g', 'cost' => 155, 'price' => 185, 'alert' => 10],
            ['name' => 'Mustard Oil 1L', 'cat' => 'Cooking Oil', 'brand' => 'Pran', 'unit' => '1L', 'cost' => 295, 'price' => 345, 'alert' => 10],
            ['name' => 'Rice Bran Oil 2L', 'cat' => 'Cooking Oil', 'brand' => 'ACI Pure', 'unit' => '2L', 'cost' => 365, 'price' => 420, 'alert' => 8],
            ['name' => 'Rice Bran Oil 5L', 'cat' => 'Cooking Oil', 'brand' => 'ACI Pure', 'unit' => '5L', 'cost' => 890, 'price' => 1020, 'alert' => 6],
            ['name' => 'Sunflower Oil 1L', 'cat' => 'Cooking Oil', 'brand' => 'Fresh', 'unit' => '1L', 'cost' => 260, 'price' => 305, 'alert' => 8],
            ['name' => 'Sunflower Oil 2L', 'cat' => 'Cooking Oil', 'brand' => 'Fresh', 'unit' => '2L', 'cost' => 515, 'price' => 595, 'alert' => 6],
            ['name' => 'Pure Desi Ghee 250g', 'cat' => 'Cooking Oil', 'brand' => 'Aarong Dairy', 'unit' => '250g', 'cost' => 360, 'price' => 420, 'alert' => 8],

            // 3. Spices (16)
            ['name' => 'Turmeric Powder 250g', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '250g', 'cost' => 85, 'price' => 105, 'alert' => 12],
            ['name' => 'Turmeric Powder 500g', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '500g', 'cost' => 165, 'price' => 198, 'alert' => 10],
            ['name' => 'Red Chili Powder 250g', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '250g', 'cost' => 102, 'price' => 125, 'alert' => 12],
            ['name' => 'Red Chili Powder 500g', 'cat' => 'Spices', 'brand' => 'ACI Pure', 'unit' => '500g', 'cost' => 195, 'price' => 235, 'alert' => 10],
            ['name' => 'Coriander Powder 250g', 'cat' => 'Spices', 'brand' => 'ACI Pure', 'unit' => '250g', 'cost' => 72, 'price' => 88, 'alert' => 12],
            ['name' => 'Cumin Powder 250g', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '250g', 'cost' => 240, 'price' => 285, 'alert' => 10],
            ['name' => 'Whole Cumin (Jira) 250g', 'cat' => 'Spices', 'brand' => 'Pran', 'unit' => '250g', 'cost' => 220, 'price' => 260, 'alert' => 10],
            ['name' => 'Garam Masala Mix 1pc', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '1pc', 'cost' => 58, 'price' => 72, 'alert' => 15],
            ['name' => 'Beef Curry Masala 1pc', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '1pc', 'cost' => 64, 'price' => 78, 'alert' => 15],
            ['name' => 'Chicken Roast Masala 1pc', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '1pc', 'cost' => 62, 'price' => 75, 'alert' => 15],
            ['name' => 'Kacchi Biryani Masala 1pc', 'cat' => 'Spices', 'brand' => 'Radhuni', 'unit' => '1pc', 'cost' => 74, 'price' => 90, 'alert' => 12],
            ['name' => 'Black Pepper Whole 250g', 'cat' => 'Spices', 'brand' => 'Pran', 'unit' => '250g', 'cost' => 230, 'price' => 275, 'alert' => 8],
            ['name' => 'Cardamom (Elachi) 250g', 'cat' => 'Spices', 'brand' => 'ACI Pure', 'unit' => '250g', 'cost' => 680, 'price' => 790, 'alert' => 6],
            ['name' => 'Cinnamon Stick 250g', 'cat' => 'Spices', 'brand' => 'Pran', 'unit' => '250g', 'cost' => 135, 'price' => 165, 'alert' => 10],
            ['name' => 'Iodized Table Salt 1kg', 'cat' => 'Spices', 'brand' => 'ACI Pure', 'unit' => '1kg', 'cost' => 34, 'price' => 42, 'alert' => 20],
            ['name' => 'Vacuum Evaporated Salt 1kg', 'cat' => 'Spices', 'brand' => 'Fresh', 'unit' => '1kg', 'cost' => 35, 'price' => 42, 'alert' => 20],

            // 4. Beverages (16)
            ['name' => 'Mirzapore Black Tea 500g', 'cat' => 'Beverages', 'brand' => 'Ispahani', 'unit' => '500g', 'cost' => 225, 'price' => 265, 'alert' => 12],
            ['name' => 'Mirzapore Black Tea 250g', 'cat' => 'Beverages', 'brand' => 'Ispahani', 'unit' => '250g', 'cost' => 118, 'price' => 140, 'alert' => 15],
            ['name' => 'Tea Bags 50pcs Box', 'cat' => 'Beverages', 'brand' => 'Ispahani', 'unit' => '1pc', 'cost' => 125, 'price' => 150, 'alert' => 12],
            ['name' => 'Green Tea 25 Bags', 'cat' => 'Beverages', 'brand' => 'Ispahani', 'unit' => '1pc', 'cost' => 115, 'price' => 140, 'alert' => 10],
            ['name' => 'Classic Instant Coffee 1pc', 'cat' => 'Beverages', 'brand' => 'Nestle BD', 'unit' => '1pc', 'cost' => 265, 'price' => 315, 'alert' => 10],
            ['name' => '3-in-1 Coffee Pack 1pc', 'cat' => 'Beverages', 'brand' => 'Nestle BD', 'unit' => '1pc', 'cost' => 185, 'price' => 220, 'alert' => 10],
            ['name' => 'Mango Fruit Drink 1L', 'cat' => 'Beverages', 'brand' => 'Pran', 'unit' => '1L', 'cost' => 88, 'price' => 105, 'alert' => 15],
            ['name' => 'Mango Juice 250ml Pack', 'cat' => 'Beverages', 'brand' => 'Pran', 'unit' => '1pc', 'cost' => 24, 'price' => 30, 'alert' => 20],
            ['name' => 'Orange Fruit Drink 1L', 'cat' => 'Beverages', 'brand' => 'Pran', 'unit' => '1L', 'cost' => 92, 'price' => 110, 'alert' => 12],
            ['name' => 'Litchi Drink 1pc', 'cat' => 'Beverages', 'brand' => 'Pran', 'unit' => '1pc', 'cost' => 24, 'price' => 30, 'alert' => 20],
            ['name' => 'Cola Soft Drink 1L', 'cat' => 'Beverages', 'brand' => 'Fresh', 'unit' => '1L', 'cost' => 58, 'price' => 70, 'alert' => 15],
            ['name' => 'Cola Soft Drink 2L', 'cat' => 'Beverages', 'brand' => 'Fresh', 'unit' => '2L', 'cost' => 108, 'price' => 130, 'alert' => 12],
            ['name' => 'Lemon Soda 1L', 'cat' => 'Beverages', 'brand' => 'Fresh', 'unit' => '1L', 'cost' => 56, 'price' => 68, 'alert' => 12],
            ['name' => 'Mineral Drinking Water 1L', 'cat' => 'Beverages', 'brand' => 'Fresh', 'unit' => '1L', 'cost' => 22, 'price' => 30, 'alert' => 20],
            ['name' => 'Mineral Drinking Water 5L', 'cat' => 'Beverages', 'brand' => 'Fresh', 'unit' => '5L', 'cost' => 72, 'price' => 90, 'alert' => 12],
            ['name' => 'Malt Health Drink 500g', 'cat' => 'Beverages', 'brand' => 'Nestle BD', 'unit' => '500g', 'cost' => 390, 'price' => 460, 'alert' => 8],

            // 5. Snacks (16)
            ['name' => 'Energy Plus Biscuit 1pc', 'cat' => 'Snacks', 'brand' => 'Olympic', 'unit' => '1pc', 'cost' => 42, 'price' => 52, 'alert' => 18],
            ['name' => 'Nutty Peanut Biscuit 1pc', 'cat' => 'Snacks', 'brand' => 'Olympic', 'unit' => '1pc', 'cost' => 48, 'price' => 60, 'alert' => 15],
            ['name' => 'Tip Salt Biscuit 1pc', 'cat' => 'Snacks', 'brand' => 'Olympic', 'unit' => '1pc', 'cost' => 36, 'price' => 45, 'alert' => 15],
            ['name' => 'Dry Cake Toast 250g', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '250g', 'cost' => 68, 'price' => 85, 'alert' => 15],
            ['name' => 'Butter Toast Biscuit 250g', 'cat' => 'Snacks', 'brand' => 'Olympic', 'unit' => '250g', 'cost' => 72, 'price' => 88, 'alert' => 12],
            ['name' => 'Potato Crackers 1pc', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '1pc', 'cost' => 16, 'price' => 20, 'alert' => 25],
            ['name' => 'Spicy Chanachur 250g', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '250g', 'cost' => 58, 'price' => 72, 'alert' => 15],
            ['name' => 'Jhal Chanachur 500g', 'cat' => 'Snacks', 'brand' => 'ACI Pure', 'unit' => '500g', 'cost' => 112, 'price' => 138, 'alert' => 12],
            ['name' => 'Roasted Peanuts 250g', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '250g', 'cost' => 68, 'price' => 85, 'alert' => 12],
            ['name' => 'Instant Masala Noodles 8-Pack', 'cat' => 'Snacks', 'brand' => 'Nestle BD', 'unit' => '1pc', 'cost' => 142, 'price' => 168, 'alert' => 15],
            ['name' => 'Egg Noodles 500g', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '500g', 'cost' => 78, 'price' => 95, 'alert' => 12],
            ['name' => 'Penne Pasta 500g', 'cat' => 'Snacks', 'brand' => 'Bashundhara', 'unit' => '500g', 'cost' => 92, 'price' => 115, 'alert' => 10],
            ['name' => 'Chocolate Cream Wafer 1pc', 'cat' => 'Snacks', 'brand' => 'Olympic', 'unit' => '1pc', 'cost' => 28, 'price' => 35, 'alert' => 18],
            ['name' => 'Vanilla Layer Cake 1pc', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '1pc', 'cost' => 24, 'price' => 30, 'alert' => 18],
            ['name' => 'Tomato Ketchup 500g', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '500g', 'cost' => 118, 'price' => 145, 'alert' => 10],
            ['name' => 'Mango Pickle (Achar) 500g', 'cat' => 'Snacks', 'brand' => 'Pran', 'unit' => '500g', 'cost' => 145, 'price' => 178, 'alert' => 10],

            // 6. Dairy (14)
            ['name' => 'Pasteurized Liquid Milk 1L', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '1L', 'cost' => 84, 'price' => 98, 'alert' => 15],
            ['name' => 'Pasteurized Liquid Milk 500ml', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '1pc', 'cost' => 45, 'price' => 54, 'alert' => 15],
            ['name' => 'UHT Full Cream Milk 1L', 'cat' => 'Dairy', 'brand' => 'Pran', 'unit' => '1L', 'cost' => 92, 'price' => 108, 'alert' => 15],
            ['name' => 'Full Cream Milk Powder 500g', 'cat' => 'Dairy', 'brand' => 'Marks', 'unit' => '500g', 'cost' => 385, 'price' => 445, 'alert' => 10],
            ['name' => 'Full Cream Milk Powder 1kg', 'cat' => 'Dairy', 'brand' => 'Marks', 'unit' => '1kg', 'cost' => 760, 'price' => 870, 'alert' => 8],
            ['name' => 'Sweetened Condensed Milk 1pc', 'cat' => 'Dairy', 'brand' => 'Marks', 'unit' => '1pc', 'cost' => 82, 'price' => 98, 'alert' => 15],
            ['name' => 'Salted Butter 250g', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '250g', 'cost' => 235, 'price' => 275, 'alert' => 10],
            ['name' => 'Sweet Yogurt (Mishti Doi) 500g', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '500g', 'cost' => 135, 'price' => 165, 'alert' => 10],
            ['name' => 'Sour Yogurt (Tok Doi) 500g', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '500g', 'cost' => 115, 'price' => 140, 'alert' => 10],
            ['name' => 'Cheddar Cheese Slice 1pc', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '1pc', 'cost' => 210, 'price' => 250, 'alert' => 8],
            ['name' => 'Mozzarella Cheese 250g', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '250g', 'cost' => 245, 'price' => 290, 'alert' => 8],
            ['name' => 'Labang Spiced Milk Drink 1pc', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '1pc', 'cost' => 62, 'price' => 75, 'alert' => 12],
            ['name' => 'Chocolate Flavored Milk 1pc', 'cat' => 'Dairy', 'brand' => 'Aarong Dairy', 'unit' => '1pc', 'cost' => 32, 'price' => 40, 'alert' => 15],
            ['name' => 'Farm Fresh Eggs 12pcs', 'cat' => 'Dairy', 'brand' => 'Kazi Farms', 'unit' => '1pc', 'cost' => 142, 'price' => 165, 'alert' => 15],

            // 7. Frozen Food (14)
            ['name' => 'Frozen Paratha 10pcs', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '1pc', 'cost' => 155, 'price' => 185, 'alert' => 12],
            ['name' => 'Frozen Aloo Paratha 10pcs', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '1pc', 'cost' => 175, 'price' => 210, 'alert' => 10],
            ['name' => 'Chicken Nuggets 250g', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '250g', 'cost' => 185, 'price' => 225, 'alert' => 10],
            ['name' => 'Chicken Nuggets 500g', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '500g', 'cost' => 355, 'price' => 420, 'alert' => 8],
            ['name' => 'Chicken Samosa 1pc', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '1pc', 'cost' => 165, 'price' => 198, 'alert' => 10],
            ['name' => 'Vegetable Spring Roll 1pc', 'cat' => 'Frozen Food', 'brand' => 'Pran', 'unit' => '1pc', 'cost' => 135, 'price' => 165, 'alert' => 10],
            ['name' => 'Chicken Sausage 250g', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '250g', 'cost' => 195, 'price' => 235, 'alert' => 10],
            ['name' => 'Frozen French Fries 500g', 'cat' => 'Frozen Food', 'brand' => 'Pran', 'unit' => '500g', 'cost' => 155, 'price' => 190, 'alert' => 10],
            ['name' => 'Frozen Green Peas 500g', 'cat' => 'Frozen Food', 'brand' => 'Pran', 'unit' => '500g', 'cost' => 125, 'price' => 155, 'alert' => 10],
            ['name' => 'Chicken Meatball 250g', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '250g', 'cost' => 190, 'price' => 230, 'alert' => 8],
            ['name' => 'Crispy Chicken Strips 250g', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '250g', 'cost' => 210, 'price' => 255, 'alert' => 8],
            ['name' => 'Beef Burger Patty 250g', 'cat' => 'Frozen Food', 'brand' => 'Kazi Farms', 'unit' => '250g', 'cost' => 265, 'price' => 320, 'alert' => 8],
            ['name' => 'Frozen Dal Puri 10pcs', 'cat' => 'Frozen Food', 'brand' => 'Pran', 'unit' => '1pc', 'cost' => 115, 'price' => 140, 'alert' => 10],
            ['name' => 'Vanilla Ice Cream 1L Tub', 'cat' => 'Frozen Food', 'brand' => 'Aarong Dairy', 'unit' => '1L', 'cost' => 245, 'price' => 295, 'alert' => 8],

            // 8. Personal Care (16)
            ['name' => 'Bathing Soap Bar 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 48, 'price' => 60, 'alert' => 20],
            ['name' => 'Antibacterial Soap 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 52, 'price' => 65, 'alert' => 20],
            ['name' => 'Sandalwood Beauty Soap 1pc', 'cat' => 'Personal Care', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 44, 'price' => 55, 'alert' => 18],
            ['name' => 'Anti-Dandruff Shampoo 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 225, 'price' => 270, 'alert' => 12],
            ['name' => 'Silky Smooth Shampoo 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 210, 'price' => 255, 'alert' => 12],
            ['name' => 'Coconut Hair Oil 250g', 'cat' => 'Personal Care', 'brand' => 'Square Toiletries', 'unit' => '250g', 'cost' => 148, 'price' => 180, 'alert' => 12],
            ['name' => 'Herbal Toothpaste 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 92, 'price' => 115, 'alert' => 15],
            ['name' => 'Fresh Gel Toothpaste 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 88, 'price' => 110, 'alert' => 15],
            ['name' => 'Soft Bristle Toothbrush 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 38, 'price' => 50, 'alert' => 18],
            ['name' => 'Moisturizing Body Lotion 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 215, 'price' => 260, 'alert' => 10],
            ['name' => 'Oil Control Face Wash 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 185, 'price' => 225, 'alert' => 10],
            ['name' => 'Liquid Handwash Refill 250g', 'cat' => 'Personal Care', 'brand' => 'Square Toiletries', 'unit' => '250g', 'cost' => 78, 'price' => 95, 'alert' => 15],
            ['name' => 'Petroleum Jelly 1pc', 'cat' => 'Personal Care', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 82, 'price' => 100, 'alert' => 12],
            ['name' => 'Men Shaving Foam 1pc', 'cat' => 'Personal Care', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 165, 'price' => 200, 'alert' => 10],
            ['name' => 'Talcum Powder 250g', 'cat' => 'Personal Care', 'brand' => 'Square Toiletries', 'unit' => '250g', 'cost' => 115, 'price' => 140, 'alert' => 10],
            ['name' => 'Facial Tissue Box 1pc', 'cat' => 'Personal Care', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 62, 'price' => 78, 'alert' => 15],

            // 9. Household Cleaning (16)
            ['name' => 'Detergent Powder 1kg', 'cat' => 'Household Cleaning', 'brand' => 'Unilever BD', 'unit' => '1kg', 'cost' => 165, 'price' => 198, 'alert' => 15],
            ['name' => 'Detergent Powder 500g', 'cat' => 'Household Cleaning', 'brand' => 'Unilever BD', 'unit' => '500g', 'cost' => 86, 'price' => 105, 'alert' => 15],
            ['name' => 'Matic Liquid Detergent 1L', 'cat' => 'Household Cleaning', 'brand' => 'Unilever BD', 'unit' => '1L', 'cost' => 295, 'price' => 350, 'alert' => 10],
            ['name' => 'Dishwashing Liquid 500g', 'cat' => 'Household Cleaning', 'brand' => 'Unilever BD', 'unit' => '500g', 'cost' => 112, 'price' => 135, 'alert' => 15],
            ['name' => 'Dishwashing Bar 1pc', 'cat' => 'Household Cleaning', 'brand' => 'Unilever BD', 'unit' => '1pc', 'cost' => 28, 'price' => 35, 'alert' => 20],
            ['name' => 'Toilet Cleaner 500g', 'cat' => 'Household Cleaning', 'brand' => 'ACI Pure', 'unit' => '500g', 'cost' => 108, 'price' => 132, 'alert' => 12],
            ['name' => 'Floor Disinfectant Liquid 1L', 'cat' => 'Household Cleaning', 'brand' => 'ACI Pure', 'unit' => '1L', 'cost' => 195, 'price' => 235, 'alert' => 10],
            ['name' => 'Glass & Surface Cleaner 500g', 'cat' => 'Household Cleaning', 'brand' => 'ACI Pure', 'unit' => '500g', 'cost' => 118, 'price' => 145, 'alert' => 10],
            ['name' => 'Mosquito Aerosol Spray 1pc', 'cat' => 'Household Cleaning', 'brand' => 'ACI Pure', 'unit' => '1pc', 'cost' => 275, 'price' => 330, 'alert' => 10],
            ['name' => 'Mosquito Coil Pack 1pc', 'cat' => 'Household Cleaning', 'brand' => 'ACI Pure', 'unit' => '1pc', 'cost' => 58, 'price' => 72, 'alert' => 18],
            ['name' => 'Air Freshener Spray 1pc', 'cat' => 'Household Cleaning', 'brand' => 'ACI Pure', 'unit' => '1pc', 'cost' => 175, 'price' => 215, 'alert' => 10],
            ['name' => 'Kitchen Towel Roll 1pc', 'cat' => 'Household Cleaning', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 68, 'price' => 85, 'alert' => 15],
            ['name' => 'Toilet Tissue Roll 4-Pack', 'cat' => 'Household Cleaning', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 118, 'price' => 145, 'alert' => 15],
            ['name' => 'Steel Scrubber Pad 1pc', 'cat' => 'Household Cleaning', 'brand' => 'Fresh', 'unit' => '1pc', 'cost' => 22, 'price' => 30, 'alert' => 20],
            ['name' => 'Garbage Bin Bags Roll 1pc', 'cat' => 'Household Cleaning', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 74, 'price' => 92, 'alert' => 12],
            ['name' => 'Fabric Softener 1L', 'cat' => 'Household Cleaning', 'brand' => 'Unilever BD', 'unit' => '1L', 'cost' => 220, 'price' => 265, 'alert' => 8],

            // 10. Baby Products (16) — includes 10 low-stock (141..150) & 5 out-of-stock (151..155)
            ['name' => 'Baby Diaper Small 32pcs', 'cat' => 'Baby Products', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 560, 'price' => 650, 'alert' => 10],
            ['name' => 'Baby Diaper Medium 30pcs', 'cat' => 'Baby Products', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 610, 'price' => 710, 'alert' => 10],
            ['name' => 'Baby Diaper Large 28pcs', 'cat' => 'Baby Products', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 650, 'price' => 760, 'alert' => 10],
            ['name' => 'Baby Diaper XL 24pcs', 'cat' => 'Baby Products', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 670, 'price' => 780, 'alert' => 10],
            ['name' => 'Baby Wet Wipes 80pcs', 'cat' => 'Baby Products', 'brand' => 'Bashundhara', 'unit' => '1pc', 'cost' => 135, 'price' => 165, 'alert' => 12],
            ['name' => 'Infant Cereal Wheat 500g', 'cat' => 'Baby Products', 'brand' => 'Nestle BD', 'unit' => '500g', 'cost' => 385, 'price' => 450, 'alert' => 10],
            ['name' => 'Infant Cereal Rice & Fruits 500g', 'cat' => 'Baby Products', 'brand' => 'Nestle BD', 'unit' => '500g', 'cost' => 410, 'price' => 480, 'alert' => 10],
            ['name' => 'Infant Formula Stage 1 500g', 'cat' => 'Baby Products', 'brand' => 'Nestle BD', 'unit' => '500g', 'cost' => 690, 'price' => 795, 'alert' => 10],
            ['name' => 'Infant Formula Stage 2 500g', 'cat' => 'Baby Products', 'brand' => 'Nestle BD', 'unit' => '500g', 'cost' => 710, 'price' => 820, 'alert' => 10],
            ['name' => 'Baby Gentle Lotion 1pc', 'cat' => 'Baby Products', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 185, 'price' => 225, 'alert' => 10],
            ['name' => 'Baby Tear-Free Shampoo 1pc', 'cat' => 'Baby Products', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 195, 'price' => 235, 'alert' => 10],
            ['name' => 'Baby Olive Massage Oil 1pc', 'cat' => 'Baby Products', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 210, 'price' => 255, 'alert' => 10],
            ['name' => 'Baby Talcum Powder 250g', 'cat' => 'Baby Products', 'brand' => 'Square Toiletries', 'unit' => '250g', 'cost' => 145, 'price' => 175, 'alert' => 10],
            ['name' => 'Baby Mild Soap Bar 1pc', 'cat' => 'Baby Products', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 68, 'price' => 85, 'alert' => 12],
            ['name' => 'Baby Feeding Bottle 250ml', 'cat' => 'Baby Products', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 240, 'price' => 295, 'alert' => 8],
            ['name' => 'Baby Rash Protection Cream 1pc', 'cat' => 'Baby Products', 'brand' => 'Square Toiletries', 'unit' => '1pc', 'cost' => 190, 'price' => 230, 'alert' => 8],
        ];
    }
}
