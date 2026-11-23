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
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\SalesPayment;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Repositories\AdjustmentRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\PurchaseReturnRepository;
use App\Repositories\SaleRepository;
use App\Repositories\SaleReturnRepository;
use App\Repositories\TransferRepository;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NorthStarUsaThreeStoreSeeder extends Seeder
{
    private const ADMIN_ID = 2;
    private const STORE_IDS = [1, 387, 816];

    public function run(): void
    {
        Model::reguard();

        $admin = User::findOrFail(self::ADMIN_ID);
        if ($admin->email !== 'admin@stockmate.com') {
            throw new \RuntimeException('Safety abort: User ID 2 is not admin@stockmate.com.');
        }

        $stores = Store::where('user_id', self::ADMIN_ID)
            ->whereIn('id', self::STORE_IDS)
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        if ($stores->count() !== 3) {
            throw new \RuntimeException('Safety abort: Expected exactly Store IDs 1, 387, 816 owned by admin@stockmate.com.');
        }

        // Idempotency check
        $store1 = $stores[1];
        if (Product::withoutGlobalScopes()->where('tenant_id', $store1->tenant_id)->where('code', 'NSM-MN-001')->exists()) {
            $this->command?->info('NorthStarUsaThreeStoreSeeder: Already transformed to NorthStar Market USA — skipping to preserve idempotency.');
            return;
        }

        $catalogPath = database_path('seeders/data/northstar_usa_catalog.json');
        if (! file_exists($catalogPath)) {
            throw new \RuntimeException("Missing verified catalog JSON: {$catalogPath}");
        }
        $catalogData = json_decode(file_get_contents($catalogPath), true);
        $productsCatalog = $catalogData['products'] ?? [];
        if (count($productsCatalog) < 150) {
            throw new \RuntimeException('Catalog must contain at least 150 verified US products.');
        }

        $usdCurrencyId = (string) (Currency::where('code', 'USD')->value('id') ?? 2);

        $branchDefs = [
            1 => [
                'name' => 'NorthStar Market – Manhattan',
                'short' => 'Manhattan',
                'prefix' => 'NSM-MN',
                'city' => 'Manhattan',
                'state' => 'NY',
                'zip' => '10001',
                'address' => '245 W 23rd St, Manhattan, New York, NY 10011, USA',
                'phone' => '(212) 555-0142',
                'stock_scale' => 1.30,
                'sales_count' => 26,
                'expense_scale' => 1.35,
            ],
            387 => [
                'name' => 'NorthStar Market – Brooklyn',
                'short' => 'Brooklyn',
                'prefix' => 'NSM-BK',
                'city' => 'Brooklyn',
                'state' => 'NY',
                'zip' => '11201',
                'address' => '184 Atlantic Ave, Brooklyn, New York, NY 11201, USA',
                'phone' => '(718) 555-0189',
                'stock_scale' => 1.05,
                'sales_count' => 23,
                'expense_scale' => 1.05,
            ],
            816 => [
                'name' => 'NorthStar Market – Queens',
                'short' => 'Queens',
                'prefix' => 'NSM-QN',
                'city' => 'Queens',
                'state' => 'NY',
                'zip' => '11101',
                'address' => '31-14 Steinway St, Astoria, Queens, New York, NY 11103, USA',
                'phone' => '(718) 555-0164',
                'stock_scale' => 0.95,
                'sales_count' => 22,
                'expense_scale' => 0.95,
            ],
        ];

        // Safely clear ONLY verified demo-owned FreshMart records in Stores 1, 387, 816
        foreach (self::STORE_IDS as $sid) {
            $this->purgeDemoTenantRecords($stores[$sid]->tenant_id);
        }

        // Rename existing stores in-place (preserving IDs 1, 387, 816 & tenant_ids) and seed USA data
        foreach (self::STORE_IDS as $idx => $sid) {
            $store = $stores[$sid];
            $cfg = $branchDefs[$sid];
            $store->update(['name' => $cfg['name']]);
            $this->seedUsaBranch($admin, $store, $cfg, $usdCurrencyId, $productsCatalog, $idx);
        }

        // Restore admin@stockmate.com active store to Store 1 (NorthStar Market – Manhattan)
        $admin->update(['tenant_id' => $stores[1]->tenant_id]);
        Auth::login($admin->fresh());

        $this->command?->info('NorthStarUsaThreeStoreSeeder: Successfully transformed Stores 1, 387, 816 into NorthStar Market USA (USD).');
    }

    private function purgeDemoTenantRecords(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId) {
            $saleIds = Sale::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
            if ($saleIds->isNotEmpty()) {
                SalesPayment::withoutGlobalScopes()->whereIn('sale_id', $saleIds)->delete();
                SaleItem::withoutGlobalScopes()->whereIn('sale_id', $saleIds)->delete();
            }
            $srIds = SaleReturn::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
            if ($srIds->isNotEmpty()) {
                SaleReturnItem::withoutGlobalScopes()->whereIn('sale_return_id', $srIds)->delete();
            }
            $purIds = Purchase::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
            if ($purIds->isNotEmpty()) {
                PurchaseItem::withoutGlobalScopes()->whereIn('purchase_id', $purIds)->delete();
            }
            $prIds = PurchaseReturn::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
            if ($prIds->isNotEmpty()) {
                PurchaseReturnItem::withoutGlobalScopes()->whereIn('purchase_return_id', $prIds)->delete();
            }
            $adjIds = Adjustment::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
            if ($adjIds->isNotEmpty()) {
                AdjustmentItem::withoutGlobalScopes()->whereIn('adjustment_id', $adjIds)->delete();
            }
            $trIds = Transfer::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
            if ($trIds->isNotEmpty()) {
                TransferItem::withoutGlobalScopes()->whereIn('transfer_id', $trIds)->delete();
            }

            StockMovement::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            ManageStock::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            SaleReturn::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Sale::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            PurchaseReturn::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Purchase::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Adjustment::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Transfer::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Expense::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            ExpenseCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Product::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            MainProduct::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            ProductCategory::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Brand::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Unit::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            BaseUnit::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            Supplier::withoutGlobalScopes()->where('tenant_id', $tenantId)->delete();
            // Keep the first default walk-in customer; delete demo customers
            $walkInId = Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->orderBy('id')->value('id');
            Customer::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', '!=', $walkInId)->delete();
        });
    }

    private function seedUsaBranch(User $admin, Store $store, array $cfg, string $usdCurrencyId, array $catalog, int $bIndex): void
    {
        $tenantId = $store->tenant_id;
        $admin->update(['tenant_id' => $tenantId]);
        Auth::login($admin->fresh());

        // 1. USD & US Locale Settings
        $settingsMap = [
            'currency' => $usdCurrencyId,
            'company_name' => $store->name,
            'store_name' => $store->name,
            'phone' => $cfg['phone'],
            'address' => $cfg['address'],
            'email' => 'hello@northstarmarket.demo',
            'date_format' => 'm/d/Y',
            'is_currency_right' => 'false',
            'sale_code' => 'NS',
            'purchase_code' => 'PO',
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

        // 2. Payment Methods (US Retail)
        $pmCash = PaymentMethod::firstOrCreate(['name' => 'Cash'], ['status' => 1]);
        $pmCard = PaymentMethod::firstOrCreate(['name' => 'Credit / Debit Card'], ['status' => 1]);
        $pmApple = PaymentMethod::firstOrCreate(['name' => 'Apple Pay / Tap to Pay'], ['status' => 1]);
        $paymentMethods = [$pmCard->id, $pmApple->id, $pmCash->id];

        // 3. Warehouses (reuse existing warehouses safely)
        $existingWhs = Warehouse::orderBy('id')->get();
        $mainWh = $existingWhs->get(0);
        if ($mainWh) {
            $mainWh->update([
                'name' => "{$cfg['short']} Backroom Stock",
                'city' => $cfg['city'],
                'country' => 'United States',
                'phone' => $cfg['phone'],
                'email' => strtolower($cfg['short']) . '.stock@northstarmarket.demo',
                'zip_code' => $cfg['zip'],
            ]);
        } else {
            $mainWh = Warehouse::create([
                'name' => "{$cfg['short']} Backroom Stock",
                'city' => $cfg['city'],
                'country' => 'United States',
                'phone' => $cfg['phone'],
                'email' => strtolower($cfg['short']) . '.stock@northstarmarket.demo',
                'zip_code' => $cfg['zip'],
            ]);
        }
        $floorWh = $existingWhs->get(1);
        if ($floorWh) {
            $floorWh->update([
                'name' => "{$cfg['short']} Sales Floor",
                'city' => $cfg['city'],
                'country' => 'United States',
                'phone' => $cfg['phone'],
                'email' => strtolower($cfg['short']) . '.floor@northstarmarket.demo',
                'zip_code' => $cfg['zip'],
            ]);
        } else {
            $floorWh = Warehouse::create([
                'name' => "{$cfg['short']} Sales Floor",
                'city' => $cfg['city'],
                'country' => 'United States',
                'phone' => $cfg['phone'],
                'email' => strtolower($cfg['short']) . '.floor@northstarmarket.demo',
                'zip_code' => $cfg['zip'],
            ]);
        }

        // 4. US Customary Base Units & Units
        $buOz = BaseUnit::firstOrCreate(['name' => 'oz'], ['is_default' => 1]);
        $buFlOz = BaseUnit::firstOrCreate(['name' => 'fl oz'], ['is_default' => 0]);
        $buLb = BaseUnit::firstOrCreate(['name' => 'lb'], ['is_default' => 0]);
        $buCt = BaseUnit::firstOrCreate(['name' => 'count'], ['is_default' => 0]);

        $units = [
            'oz' => Unit::firstOrCreate(['short_name' => 'oz'], ['name' => 'Ounce (oz)', 'base_unit' => $buOz->id]),
            'fl oz' => Unit::firstOrCreate(['short_name' => 'fl oz'], ['name' => 'Fluid Ounce (fl oz)', 'base_unit' => $buFlOz->id]),
            'lb' => Unit::firstOrCreate(['short_name' => 'lb'], ['name' => 'Pound (lb)', 'base_unit' => $buLb->id]),
            'ct' => Unit::firstOrCreate(['short_name' => 'ct'], ['name' => 'Count (ct)', 'base_unit' => $buCt->id]),
            'pack' => Unit::firstOrCreate(['short_name' => 'pack'], ['name' => 'Pack', 'base_unit' => $buCt->id]),
            'ea' => Unit::firstOrCreate(['short_name' => 'ea'], ['name' => 'Each', 'base_unit' => $buCt->id]),
        ];
        $baseByKey = [
            'oz' => $buOz->id, 'fl oz' => $buFlOz->id, 'lb' => $buLb->id,
            'ct' => $buCt->id, 'pack' => $buCt->id, 'ea' => $buCt->id,
        ];

        // 5. All 14 Professional US Supermarket Categories
        $catNames = [
            'Fresh Produce', 'Meat & Seafood', 'Dairy & Eggs', 'Bakery',
            'Beverages', 'Snacks & Candy', 'Breakfast & Cereal', 'Pantry & Cooking',
            'Frozen Foods', 'Household Essentials', 'Personal Care', 'Baby Products',
            'Pet Supplies', 'Health & Wellness',
        ];
        $cats = [];
        foreach ($catNames as $cn) {
            $cats[$cn] = ProductCategory::firstOrCreate(['name' => $cn]);
        }

        // 6. Brands
        $brands = [];
        foreach ($catalog as $item) {
            $bn = $item['brand'];
            if (! isset($brands[$bn])) {
                $brands[$bn] = Brand::firstOrCreate(['name' => $bn]);
            }
        }

        // 7. US Wholesale Suppliers (8)
        $supplierDefs = [
            ['UNFI Northeast Distribution', '(800) 555-0111', 'New York'],
            ['Sysco Metro New York', '(800) 555-0112', 'Jersey City'],
            ['C&S Wholesale Grocers', '(800) 555-0113', 'Keene'],
            ['KeHE Distributors East', '(800) 555-0114', 'Newark'],
            ['Liberty Coca-Cola Beverages NY', '(212) 555-0115', 'Bronx'],
            ['PepsiCo Northeast Direct', '(914) 555-0116', 'Purchase'],
            ['Procter & Gamble Retail Supply', '(800) 555-0117', 'Cincinnati'],
            ['Hunts Point Fresh Produce Co-Op', '(718) 555-0118', 'Bronx'],
        ];
        $suppliers = [];
        foreach ($supplierDefs as $idx => [$sname, $sphone, $scity]) {
            $suppliers[] = Supplier::firstOrCreate(
                ['email' => strtolower($cfg['short']) . ".vendor{$idx}@northstarmarket.demo"],
                [
                    'name' => $sname,
                    'phone' => $sphone,
                    'country' => 'United States',
                    'city' => $scity,
                    'address' => "Distribution Center, {$scity}, USA",
                ]
            );
        }

        // 8. US Customers (preserve walk-in + add 12 NYC neighborhood shoppers)
        $customerNames = [
            'Emily Johnson', 'Michael Carter', 'Sarah Martinez', 'David Chen',
            'Jessica Williams', 'Daniel Brooks', 'Ashley Patel', 'Christopher Kim',
            'Amanda Rivera', 'Matthew Thompson', 'Lauren Garcia', 'Joshua Wright',
        ];
        $customers = [];
        $walkIn = Customer::first();
        if ($walkIn) {
            $customers[] = $walkIn;
        }
        foreach ($customerNames as $idx => $cname) {
            $customers[] = Customer::firstOrCreate(
                ['email' => strtolower($cfg['short']) . ".shopper{$idx}@northstarmarket.demo"],
                [
                    'name' => $cname,
                    'phone' => '(917) 555-' . str_pad((string) (2000 + $bIndex * 100 + $idx), 4, '0', STR_PAD_LEFT),
                    'country' => 'United States',
                    'city' => $cfg['city'],
                    'address' => (120 + $idx * 14) . " Main Ave, {$cfg['city']}, NY {$cfg['zip']}",
                ]
            );
        }

        // 9. Products (all 159 verified US SKUs) + Opening Stock (12 low-stock, 5 out-of-stock)
        $products = [];
        $totalCount = count($catalog);
        foreach ($catalog as $i => $item) {
            $seq = str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $sku = "{$cfg['prefix']}-{$seq}";
            $upc = ! empty($item['upc']) ? $item['upc'] : ('0700' . ($bIndex + 1) . str_pad((string) ($i + 1), 7, '0', STR_PAD_LEFT));
            $unitKey = $item['unit'] ?? 'oz';
            $unitObj = $units[$unitKey] ?? $units['oz'];
            $baseUnitId = $baseByKey[$unitKey] ?? $buOz->id;

            $cost = (float) $item['cost'];
            $price = (float) $item['price'];
            $alert = (int) ($item['alert'] ?? 10);

            $expiry = in_array($item['category'], ['Dairy & Eggs', 'Fresh Produce', 'Meat & Seafood', 'Bakery'], true)
                ? Carbon::today()->addDays(6 + ($i % 18))->format('Y-m-d')
                : null;

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
                'product_code' => $upc,
                'product_category_id' => $cats[$item['category']]->id,
                'brand_id' => $brands[$item['brand']]->id,
                'product_cost' => $cost,
                'product_price' => $price,
                'product_unit' => $baseUnitId,
                'sale_unit' => $unitObj->id,
                'purchase_unit' => $unitObj->id,
                'stock_alert' => $alert,
                'quantity_limit' => 250,
                'order_tax' => 0,
                'tax_type' => 1,
                'barcode_symbol' => 1,
                'expiry_date' => $expiry,
                'notes' => "{$item['brand']} • {$item['pkg']} • {$item['category']}",
            ]);

            if ($i >= $totalCount - 5) {
                $openingQty = 0; // 5 out-of-stock SKUs
            } elseif ($i >= $totalCount - 17) {
                $openingQty = max(2, min($alert - 1, 3 + ($i % 5))); // 12 low-stock SKUs
            } else {
                $openingQty = (int) round((32 + (($i * 7) % 58)) * $cfg['stock_scale']);
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
                    "Opening stock — {$cfg['short']}"
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

        // 10. Purchases (10 received supplier POs via PurchaseRepository)
        /** @var PurchaseRepository $purchaseRepo */
        $purchaseRepo = app(PurchaseRepository::class);
        $createdPurchases = [];
        for ($p = 0; $p < 10; $p++) {
            $sup = $suppliers[$p % count($suppliers)];
            $pDate = Carbon::today()->subDays(16 - $p)->format('Y-m-d');
            $pItems = [];
            $grand = 0;
            for ($k = 0; $k < 5; $k++) {
                $prod = $products[($p * 11 + $k * 3) % 120];
                $qty = 18 + (($p + $k) % 4) * 6;
                $sub = round($qty * (float) $prod->product_cost, 2);
                $grand = round($grand + $sub, 2);
                $pItems[] = [
                    'product_id' => $prod->id,
                    'product_cost' => (float) $prod->product_cost,
                    'net_unit_cost' => (float) $prod->product_cost,
                    'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'purchase_unit' => $prod->purchase_unit,
                    'quantity' => $qty,
                    'sub_total' => $sub,
                ];
            }
            $purchase = $purchaseRepo->storePurchase([
                'date' => $pDate,
                'supplier_id' => $sup->id,
                'warehouse_id' => $mainWh->id,
                'tax_rate' => 0, 'tax_amount' => 0, 'discount' => 0, 'shipping' => 0,
                'grand_total' => $grand, 'received_amount' => $grand, 'paid_amount' => $grand,
                'payment_type' => $pmCard->id,
                'status' => Purchase::RECEIVED,
                'notes' => "Weekly wholesale restock — {$cfg['short']}",
                'purchase_items' => $pItems,
            ]);
            $createdPurchases[] = ['purchase' => $purchase, 'items' => $pItems, 'supplier_id' => $sup->id];
        }

        // 11. Purchase Return (1 per branch)
        /** @var PurchaseReturnRepository $purchaseReturnRepo */
        $purchaseReturnRepo = app(PurchaseReturnRepository::class);
        $firstPur = $createdPurchases[0];
        $retPurItem = $firstPur['items'][0];
        $retQty = 2;
        $retSub = round($retQty * (float) $retPurItem['product_cost'], 2);
        $beforePrQty = (float) ManageStock::whereWarehouseId($mainWh->id)->whereProductId($retPurItem['product_id'])->value('quantity');
        $pr = $purchaseReturnRepo->storePurchaseReturn([
            'date' => Carbon::today()->subDays(4)->format('Y-m-d'),
            'supplier_id' => $firstPur['supplier_id'],
            'warehouse_id' => $mainWh->id,
            'purchase_id' => $firstPur['purchase']->id,
            'tax_rate' => 0, 'tax_amount' => 0, 'discount' => 0, 'shipping' => 0,
            'grand_total' => $retSub, 'received_amount' => $retSub, 'paid_amount' => $retSub,
            'payment_type' => $pmCard->id, 'payment_status' => 1, 'status' => 1,
            'notes' => "Damaged case vendor return — {$cfg['short']}",
            'purchase_return_items' => [[
                'product_id' => $retPurItem['product_id'],
                'product_cost' => $retPurItem['product_cost'],
                'net_unit_cost' => $retPurItem['net_unit_cost'],
                'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                'purchase_unit' => $retPurItem['purchase_unit'],
                'quantity' => $retQty,
                'sub_total' => $retSub,
            ]],
        ]);
        recordStockMovement(
            (int) $mainWh->id, (int) $retPurItem['product_id'], 'purchase_return',
            -(float) $retQty, $beforePrQty, $beforePrQty - (float) $retQty,
            PurchaseReturn::class, $pr->id, (float) $retPurItem['product_cost'], 'Vendor purchase return'
        );

        // 12. POS Sales (22–26 completed USD sales via SaleRepository)
        /** @var SaleRepository $saleRepo */
        $saleRepo = app(SaleRepository::class);
        $createdSales = [];
        for ($s = 0; $s < $cfg['sales_count']; $s++) {
            $cust = $customers[$s % count($customers)];
            $pmId = $paymentMethods[$s % count($paymentMethods)];
            $sDate = Carbon::today()->subDays(min(13, $cfg['sales_count'] - 1 - $s))->format('Y-m-d');
            $sItems = [];
            $grand = 0;
            for ($k = 0; $k < 4; $k++) {
                $prod = $products[($s * 5 + $k * 7) % 125];
                $qty = 1 + (($s + $k) % 3);
                $sub = round($qty * (float) $prod->product_price, 2);
                $grand = round($grand + $sub, 2);
                $sItems[] = [
                    'product_id' => $prod->id,
                    'product_price' => (float) $prod->product_price,
                    'net_unit_price' => (float) $prod->product_price,
                    'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'sale_unit' => $prod->sale_unit,
                    'quantity' => $qty,
                    'sub_total' => $sub,
                ];
            }
            $sale = $saleRepo->storeSale([
                'date' => $sDate,
                'customer_id' => $cust->id,
                'warehouse_id' => $mainWh->id,
                'tax_rate' => 0, 'tax_amount' => 0, 'discount' => 0, 'shipping' => 0,
                'grand_total' => $grand, 'received_amount' => $grand, 'paid_amount' => $grand,
                'payment_type' => $pmId, 'payment_status' => Sale::PAID, 'status' => Sale::COMPLETED,
                'is_sale_created' => true,
                'note' => "POS Register Sale — {$cfg['short']}",
                'payment_details' => [['payment_type' => ['value' => $pmId], 'amount' => $grand]],
                'sale_items' => $sItems,
            ]);
            $createdSales[] = ['sale' => $sale, 'items' => $sItems, 'customer_id' => $cust->id];
        }

        // 13. Sales Returns (2 per branch via SaleReturnRepository)
        /** @var SaleReturnRepository $saleReturnRepo */
        $saleReturnRepo = app(SaleReturnRepository::class);
        for ($r = 0; $r < 2; $r++) {
            $target = $createdSales[$r * 3];
            $item = $target['items'][0];
            $rQty = 1;
            $rSub = round($rQty * (float) $item['product_price'], 2);
            $saleReturnRepo->storeSaleReturn([
                'date' => Carbon::today()->subDays(2 - $r)->format('Y-m-d'),
                'customer_id' => $target['customer_id'],
                'warehouse_id' => $mainWh->id,
                'sale_id' => $target['sale']->id,
                'tax_rate' => 0, 'tax_amount' => 0, 'discount' => 0, 'shipping' => 0,
                'grand_total' => $rSub, 'paid_amount' => $rSub,
                'payment_type' => $pmCard->id, 'payment_status' => 1, 'status' => 1,
                'note' => "Customer return — {$cfg['short']}",
                'sale_return_items' => [[
                    'product_id' => $item['product_id'],
                    'product_price' => $item['product_price'],
                    'net_unit_price' => $item['net_unit_price'],
                    'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'sale_unit' => $item['sale_unit'],
                    'quantity' => $rQty,
                    'sub_total' => $rSub,
                ]],
            ]);
        }

        // 14. Stock Adjustments (3 per branch)
        /** @var AdjustmentRepository $adjRepo */
        $adjRepo = app(AdjustmentRepository::class);
        foreach ([
            [$products[3], 2, AdjustmentItem::METHOD_SUBTRACTION, 4],
            [$products[9], 4, AdjustmentItem::METHOD_ADDITION, 2],
            [$products[15], 1, AdjustmentItem::METHOD_SUBTRACTION, 1],
        ] as [$prod, $qty, $method, $daysAgo]) {
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

        // 15. Intra-Branch Warehouse Transfers (3 per branch: Backroom Stock -> Sales Floor)
        /** @var TransferRepository $transferRepo */
        $transferRepo = app(TransferRepository::class);
        $trStatuses = [Transfer::COMPLETED, Transfer::SENT, Transfer::PENDING];
        for ($t = 0; $t < 3; $t++) {
            $prod = $products[6 + $t * 8];
            $tQty = 10;
            $tSub = round($tQty * (float) $prod->product_price, 2);
            $transferRepo->storeTransfer([
                'date' => Carbon::today()->subDays(5 - $t)->format('Y-m-d'),
                'from_warehouse_id' => $mainWh->id,
                'to_warehouse_id' => $floorWh->id,
                'tax_rate' => 0, 'tax_amount' => 0, 'discount' => 0, 'shipping' => 0,
                'grand_total' => $tSub,
                'status' => $trStatuses[$t],
                'note' => "Aisle restock {$cfg['short']} Backroom -> Sales Floor",
                'transfer_items' => [[
                    'product_id' => $prod->id,
                    'product_price' => (float) $prod->product_price,
                    'net_unit_price' => (float) $prod->product_price,
                    'tax_type' => 1, 'tax_value' => 0, 'tax_amount' => 0,
                    'discount_type' => 2, 'discount_value' => 0, 'discount_amount' => 0,
                    'quantity' => $tQty,
                    'sub_total' => $tSub,
                ]],
            ]);
        }

        // 16. US Retail Operating Expenses (10 per branch)
        $expCatNames = ['Retail Lease', 'Utilities & Refrigeration', 'Store Payroll', 'Delivery & Logistics', 'Store Supplies'];
        $expCats = [];
        foreach ($expCatNames as $ec) {
            $expCats[$ec] = ExpenseCategory::firstOrCreate(['name' => $ec]);
        }
        $expenseDefs = [
            ['Retail Lease', 'Monthly NYC Storefront Lease', 8500, 15],
            ['Store Payroll', 'Bi-Weekly Store Associates Payroll', 6200, 12],
            ['Utilities & Refrigeration', 'Con Edison Electric & Cold-Chain', 1450, 10],
            ['Utilities & Refrigeration', 'Commercial Refrigeration Maintenance', 480, 8],
            ['Delivery & Logistics', 'Regional Freight & Pallet Receiving', 620, 7],
            ['Store Supplies', 'Kraft Paper Bags & Produce Rolls', 310, 6],
            ['Store Payroll', 'Night Restocking Crew Overtime', 740, 5],
            ['Store Supplies', 'POS Thermal Receipt Paper & Labels', 185, 4],
            ['Delivery & Logistics', 'Local Same-Day Curbside Supplies', 260, 2],
            ['Utilities & Refrigeration', 'Sanitation & Waste Management', 340, 1],
        ];
        foreach ($expenseDefs as [$catName, $title, $baseAmt, $daysAgo]) {
            Expense::create([
                'date' => Carbon::today()->subDays($daysAgo)->format('Y-m-d'),
                'warehouse_id' => $mainWh->id,
                'expense_category_id' => $expCats[$catName]->id,
                'amount' => round($baseAmt * $cfg['expense_scale'], 2),
                'title' => "{$title} ({$cfg['short']})",
                'details' => "Monthly retail operating expense — {$store->name}",
            ]);
        }
    }
}
