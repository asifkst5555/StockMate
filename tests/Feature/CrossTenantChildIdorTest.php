<?php

namespace Tests\Feature;

use App\Models\BaseUnit;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesPayment;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Tests\TestCase;

class CrossTenantChildIdorTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;

    private ?Warehouse $whA = null;
    private ?Warehouse $whB = null;
    private ?Customer $customerA = null;
    private ?Customer $customerB = null;
    private ?Supplier $supplierA = null;
    private ?Supplier $supplierB = null;
    private ?PaymentMethod $payMethodA = null;
    private ?PaymentMethod $payMethodB = null;

    private ?MainProduct $mainProductA = null;
    private ?MainProduct $mainProductB = null;
    private ?Product $productA = null;
    private ?Product $productB = null;
    private ?Media $mediaA = null;
    private ?Media $mediaB = null;

    private ?Sale $saleA = null;
    private ?Sale $saleB = null;
    private ?SaleItem $saleItemA = null;
    private ?SaleItem $saleItemB = null;
    private ?SalesPayment $paymentA = null;
    private ?SalesPayment $paymentB = null;

    private ?Purchase $purchaseA = null;
    private ?Purchase $purchaseB = null;
    private ?PurchaseItem $purchaseItemA = null;
    private ?PurchaseItem $purchaseItemB = null;

    private string $protectedFileB = '';
    private array $cleanupBrandIds = [];
    private array $cleanupCategoryIds = [];
    private array $cleanupUnitIds = [];
    private array $cleanupBaseUnitIds = [];
    private array $cleanupSubscriptionIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'idor_tA_' . $this->token;
        $this->tenantBId = 'idor_tB_' . $this->token;

        DB::table('tenants')->insert([
            [
                'id' => $this->tenantAId,
                'store_id' => "idor_store_a_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
            [
                'id' => $this->tenantBId,
                'store_id' => "idor_store_b_{$this->token}",
                'created_at' => now(),
                'updated_at' => now(),
                'data' => '{}',
            ],
        ]);

        $this->adminA = $this->createAdmin("IdorAdminA_{$this->token}", "idor.a.{$this->token}@stockmate.test", $this->tenantAId);
        $this->adminB = $this->createAdmin("IdorAdminB_{$this->token}", "idor.b.{$this->token}@stockmate.test", $this->tenantBId);

        $this->storeA = Store::create([
            'name' => "IDOR Store A {$this->token}",
            'tenant_id' => $this->tenantAId,
            'user_id' => $this->adminA->id,
            'status' => 1,
        ]);
        $this->storeB = Store::create([
            'name' => "IDOR Store B {$this->token}",
            'tenant_id' => $this->tenantBId,
            'user_id' => $this->adminB->id,
            'status' => 1,
        ]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $plan = Plan::first();
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

        $this->whA = $this->createWarehouse("WH_A_{$this->token}", $this->tenantAId);
        $this->whB = $this->createWarehouse("WH_B_{$this->token}", $this->tenantBId);

        $this->customerA = $this->createCustomer("CustA_{$this->token}", $this->tenantAId);
        $this->customerB = $this->createCustomer("CustB_{$this->token}", $this->tenantBId);

        $this->supplierA = $this->createSupplier("SuppA_{$this->token}", $this->tenantAId);
        $this->supplierB = $this->createSupplier("SuppB_{$this->token}", $this->tenantBId);

        $this->payMethodA = $this->createPaymentMethod("CashA_{$this->token}", $this->tenantAId);
        $this->payMethodB = $this->createPaymentMethod("CashB_{$this->token}", $this->tenantBId);

        [$this->mainProductA, $this->productA, $this->mediaA] = $this->createProductWithMedia("ProdA_{$this->token}", "IDOR_A_{$this->token}", $this->tenantAId, $this->whA->id);
        [$this->mainProductB, $this->productB, $this->mediaB] = $this->createProductWithMedia("ProdB_{$this->token}", "IDOR_B_{$this->token}", $this->tenantBId, $this->whB->id);

        // Tenant A & B Sales + SaleItems + SalesPayments
        [$this->saleA, $this->saleItemA, $this->paymentA] = $this->createSaleBundle($this->tenantAId, $this->adminA->id, $this->customerA->id, $this->whA->id, $this->productA, $this->payMethodA->id, "SA_{$this->token}");
        [$this->saleB, $this->saleItemB, $this->paymentB] = $this->createSaleBundle($this->tenantBId, $this->adminB->id, $this->customerB->id, $this->whB->id, $this->productB, $this->payMethodB->id, "SB_{$this->token}");

        // Tenant A & B Purchases + PurchaseItems
        [$this->purchaseA, $this->purchaseItemA] = $this->createPurchaseBundle($this->tenantAId, $this->adminA->id, $this->supplierA->id, $this->whA->id, $this->productA, $this->payMethodA->id, "PA_{$this->token}");
        [$this->purchaseB, $this->purchaseItemB] = $this->createPurchaseBundle($this->tenantBId, $this->adminB->id, $this->supplierB->id, $this->whB->id, $this->productB, $this->payMethodB->id, "PB_{$this->token}");

        // Tenant B protected file on local_protected disk
        $this->protectedFileB = "tenants/{$this->tenantBId}/reports/secret_{$this->token}.txt";
        Storage::disk(config('filesystems.protected', 'local_protected'))->put($this->protectedFileB, 'tenant-b-secret');
    }

    protected function tearDown(): void
    {
        if ($this->protectedFileB) {
            Storage::disk(config('filesystems.protected', 'local_protected'))->delete($this->protectedFileB);
        }

        foreach ([$this->mainProductA, $this->mainProductB] as $mp) {
            if ($mp) {
                $mp->clearMediaCollection(MainProduct::PATH);
            }
        }

        $saleIds = array_filter([$this->saleA?->id, $this->saleB?->id]);
        if (! empty($saleIds)) {
            SalesPayment::whereIn('sale_id', $saleIds)->delete();
            SaleItem::whereIn('sale_id', $saleIds)->delete();
            Sale::withoutGlobalScope('tenant')->whereIn('id', $saleIds)->forceDelete();
        }

        $purchaseIds = array_filter([$this->purchaseA?->id, $this->purchaseB?->id]);
        if (! empty($purchaseIds)) {
            PurchaseItem::whereIn('purchase_id', $purchaseIds)->delete();
            Purchase::withoutGlobalScope('tenant')->whereIn('id', $purchaseIds)->forceDelete();
        }

        $productIds = array_filter([$this->productA?->id, $this->productB?->id]);
        if (! empty($productIds)) {
            ManageStock::withoutGlobalScope('tenant')->whereIn('product_id', $productIds)->delete();
            Product::withoutGlobalScope('tenant')->whereIn('id', $productIds)->forceDelete();
        }

        $mpIds = array_filter([$this->mainProductA?->id, $this->mainProductB?->id]);
        if (! empty($mpIds)) {
            MainProduct::withoutGlobalScope('tenant')->whereIn('id', $mpIds)->forceDelete();
        }

        $pmIds = array_filter([$this->payMethodA?->id, $this->payMethodB?->id]);
        if (! empty($pmIds)) {
            PaymentMethod::withoutGlobalScope('tenant')->whereIn('id', $pmIds)->delete();
        }

        $custIds = array_filter([$this->customerA?->id, $this->customerB?->id]);
        if (! empty($custIds)) {
            Customer::withoutGlobalScope('tenant')->whereIn('id', $custIds)->forceDelete();
        }

        $suppIds = array_filter([$this->supplierA?->id, $this->supplierB?->id]);
        if (! empty($suppIds)) {
            Supplier::withoutGlobalScope('tenant')->whereIn('id', $suppIds)->forceDelete();
        }

        $whIds = array_filter([$this->whA?->id, $this->whB?->id]);
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
        if (! empty($this->cleanupSubscriptionIds)) {
            Subscription::whereIn('id', $this->cleanupSubscriptionIds)->delete();
        }

        $userIds = array_filter([$this->adminA?->id, $this->adminB?->id]);
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

    private function createAdmin(string $name, string $email, string $tenantId): User
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $name,
            'last_name' => 'Admin',
            'email' => $email,
            'phone' => '92303' . random_int(1000000, 9999999),
            'password' => Hash::make('TestPass123!'),
            'tenant_id' => $tenantId,
            'status' => 1,
            'email_verified_at' => now(),
            'language' => 'en',
        ]);
        $user->assignRole(Role::ADMIN);

        return $user;
    }

    private function createWarehouse(string $name, string $tenantId): Warehouse
    {
        $wh = new Warehouse([
            'name' => $name,
            'phone' => '92304' . random_int(1000000, 9999999),
            'country' => 'PK',
            'city' => 'Karachi',
            'email' => Str::lower($name) . '@stockmate.test',
            'zip_code' => '74000',
        ]);
        $wh->tenant_id = $tenantId;
        $wh->save();

        return $wh;
    }

    private function createCustomer(string $name, string $tenantId): Customer
    {
        $c = new Customer([
            'name' => $name,
            'email' => Str::lower($name) . '@stockmate.test',
            'phone' => '92305' . random_int(1000000, 9999999),
            'country' => 'PK',
            'city' => 'Karachi',
            'address' => 'Test St',
        ]);
        $c->tenant_id = $tenantId;
        $c->save();

        return $c;
    }

    private function createSupplier(string $name, string $tenantId): Supplier
    {
        $s = new Supplier([
            'name' => $name,
            'email' => Str::lower($name) . '@stockmate.test',
            'phone' => '92306' . random_int(1000000, 9999999),
            'country' => 'PK',
            'city' => 'Karachi',
            'address' => 'Supplier St',
        ]);
        $s->tenant_id = $tenantId;
        $s->save();

        return $s;
    }

    private function createPaymentMethod(string $name, string $tenantId): PaymentMethod
    {
        $pm = new PaymentMethod([
            'name' => $name,
            'status' => 1,
        ]);
        $pm->tenant_id = $tenantId;
        $pm->save();

        return $pm;
    }

    private function createProductWithMedia(string $name, string $code, string $tenantId, int $warehouseId): array
    {
        $brand = new Brand(['name' => "Brand_{$code}"]);
        $brand->tenant_id = $tenantId;
        $brand->save();
        $this->cleanupBrandIds[] = $brand->id;

        $category = new ProductCategory(['name' => "Cat_{$code}"]);
        $category->tenant_id = $tenantId;
        $category->save();
        $this->cleanupCategoryIds[] = $category->id;

        $baseUnit = new BaseUnit(['name' => "Pcs_{$code}"]);
        $baseUnit->tenant_id = $tenantId;
        $baseUnit->save();
        $this->cleanupBaseUnitIds[] = $baseUnit->id;

        $unit = new Unit(['name' => "Piece_{$code}", 'short_name' => 'pc', 'base_unit' => $baseUnit->id]);
        $unit->tenant_id = $tenantId;
        $unit->save();
        $this->cleanupUnitIds[] = $unit->id;

        $mp = new MainProduct([
            'name' => $name,
            'code' => $code,
            'product_unit' => $baseUnit->id,
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);
        $mp->tenant_id = $tenantId;
        $mp->save();

        $img = UploadedFile::fake()->image("img_{$code}.jpg", 100, 100);
        $media = $mp->addMedia($img)->toMediaCollection(MainProduct::PATH, config('app.media_disc'));

        $product = Product::withoutGlobalScope('tenant')->create([
            'name' => $name,
            'code' => $code,
            'product_code' => $code,
            'main_product_id' => $mp->id,
            'product_category_id' => $category->id,
            'brand_id' => $brand->id,
            'product_cost' => 10,
            'product_price' => 25,
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

        return [$mp, $product, $media];
    }

    private function createSaleBundle(string $tenantId, int $userId, int $customerId, int $whId, Product $product, int $payMethodId, string $ref): array
    {
        $sale = new Sale([
            'date' => Carbon::today()->toDateString(),
            'customer_id' => $customerId,
            'warehouse_id' => $whId,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 50,
            'received_amount' => 20,
            'paid_amount' => 20,
            'payment_type' => $payMethodId,
            'status' => Sale::COMPLETED,
            'payment_status' => Sale::PARTIAL_PAID,
            'reference_code' => $ref,
            'user_id' => $userId,
        ]);
        $sale->tenant_id = $tenantId;
        $sale->save();

        $item = $sale->saleItems()->create([
            'product_id' => $product->id,
            'product_price' => 25,
            'net_unit_price' => 25,
            'tax_type' => 1,
            'tax_value' => 0,
            'tax_amount' => 0,
            'discount_type' => 2,
            'discount_value' => 0,
            'discount_amount' => 0,
            'sale_unit' => $product->sale_unit,
            'quantity' => 2,
            'sub_total' => 50,
        ]);

        $payment = SalesPayment::create([
            'sale_id' => $sale->id,
            'reference' => 'PAY_' . $ref,
            'payment_date' => Carbon::today()->toDateString(),
            'payment_type' => $payMethodId,
            'amount' => 20,
            'received_amount' => 20,
        ]);

        return [$sale, $item, $payment];
    }

    private function createPurchaseBundle(string $tenantId, int $userId, int $supplierId, int $whId, Product $product, int $payMethodId, string $ref): array
    {
        $purchase = new Purchase([
            'date' => Carbon::today()->toDateString(),
            'supplier_id' => $supplierId,
            'warehouse_id' => $whId,
            'tax_rate' => 0,
            'tax_amount' => 0,
            'discount' => 0,
            'shipping' => 0,
            'grand_total' => 30,
            'received_amount' => 30,
            'paid_amount' => 30,
            'payment_type' => $payMethodId,
            'status' => Purchase::RECEIVED,
            'payment_status' => 1,
            'reference_code' => $ref,
            'user_id' => $userId,
        ]);
        $purchase->tenant_id = $tenantId;
        $purchase->save();

        $item = $purchase->purchaseItems()->create([
            'product_id' => $product->id,
            'product_cost' => 10,
            'net_unit_cost' => 10,
            'tax_type' => 1,
            'tax_value' => 0,
            'tax_amount' => 0,
            'discount_type' => 2,
            'discount_value' => 0,
            'discount_amount' => 0,
            'purchase_unit' => $product->purchase_unit,
            'quantity' => 3,
            'sub_total' => 30,
        ]);

        return [$purchase, $item];
    }

    public function test_tenant_a_cannot_read_update_or_delete_tenant_b_sales_payment(): void
    {
        // 1. Read Tenant B sale payments -> 404
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/sales/{$this->saleB->id}/payments")
            ->assertStatus(404);

        // 2. Update Tenant B payment -> 404 & unchanged
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/sales/{$this->paymentB->id}/payment", [
                'amount' => 1,
                'payment_type' => $this->payMethodA->id,
                'payment_date' => Carbon::today()->toDateString(),
                'sale_id' => $this->saleA->id,
            ])
            ->assertStatus(404);

        $this->paymentB->refresh();
        $this->saleB->refresh();
        $this->assertEquals(20, (float) $this->paymentB->amount);
        $this->assertEquals($this->saleB->id, $this->paymentB->sale_id);
        $this->assertEquals(20, (float) $this->saleB->paid_amount);

        // 3. Delete Tenant B payment -> 404 & still exists
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/sales/{$this->paymentB->id}/payment")
            ->assertStatus(404);

        $this->assertDatabaseHas('sales_payments', ['id' => $this->paymentB->id, 'amount' => 20]);
        $this->saleB->refresh();
        $this->assertEquals(20, (float) $this->saleB->paid_amount);
    }

    public function test_tenant_a_cannot_access_or_mutate_tenant_b_sale_or_cross_tenant_child_ids(): void
    {
        $stockBBefore = (float) ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $this->whB->id)->where('product_id', $this->productB->id)->value('quantity');
        $stockABefore = (float) ManageStock::withoutGlobalScope('tenant')
            ->where('warehouse_id', $this->whA->id)->where('product_id', $this->productA->id)->value('quantity');

        // 1. Read Tenant B sale -> 404
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/sales/{$this->saleB->id}")
            ->assertStatus(404);

        // 2. Update Tenant B parent sale -> 404 or 422 (P0-C rejects foreign sale_items.*.product_id at validation)
        $updateForeignSale = $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/sales/{$this->saleB->id}", [
                'date' => Carbon::today()->toDateString(),
                'customer_id' => $this->customerA->id,
                'warehouse_id' => $this->whA->id,
                'tax_rate' => 0,
                'discount' => 0,
                'shipping' => 0,
                'grand_total' => 1,
                'payment_status' => Sale::UNPAID,
                'status' => Sale::COMPLETED,
                'sale_items' => [[
                    'sale_item_id' => $this->saleItemB->id,
                    'product_id' => $this->productB->id,
                    'product_price' => 1,
                    'net_unit_price' => 1,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $this->productB->sale_unit,
                    'quantity' => 1,
                    'sub_total' => 1,
                ]],
            ]);
        $this->assertContains($updateForeignSale->status(), [404, 422]);

        // 3. Combine Tenant A parent sale with Tenant B's sale_item_id -> 404 & atomic rollback
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/sales/{$this->saleA->id}", [
                'date' => Carbon::today()->toDateString(),
                'customer_id' => $this->customerA->id,
                'warehouse_id' => $this->whA->id,
                'tax_rate' => 0,
                'discount' => 0,
                'shipping' => 0,
                'grand_total' => 5,
                'payment_status' => Sale::UNPAID,
                'status' => Sale::COMPLETED,
                'sale_items' => [[
                    'sale_item_id' => $this->saleItemB->id,
                    'product_id' => $this->productA->id,
                    'product_price' => 1,
                    'net_unit_price' => 1,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $this->productA->sale_unit,
                    'quantity' => 5,
                    'sub_total' => 5,
                ]],
            ])
            ->assertStatus(404);

        // 4. Combine Tenant A parent sale with Tenant B's payment_details[0].id -> 404 & atomic rollback
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/sales/{$this->saleA->id}", [
                'date' => Carbon::today()->toDateString(),
                'customer_id' => $this->customerA->id,
                'warehouse_id' => $this->whA->id,
                'tax_rate' => 0,
                'discount' => 0,
                'shipping' => 0,
                'payment_status' => Sale::PARTIAL_PAID,
                'status' => Sale::COMPLETED,
                'sale_items' => [[
                    'sale_item_id' => $this->saleItemA->id,
                    'product_id' => $this->productA->id,
                    'product_price' => 25,
                    'net_unit_price' => 25,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $this->productA->sale_unit,
                    'quantity' => 2,
                    'sub_total' => 50,
                ]],
                'payment_details' => [[
                    'id' => $this->paymentB->id,
                    'amount' => 50,
                    'payment_type' => ['value' => $this->payMethodA->id],
                    'date' => Carbon::today()->toDateString(),
                ]],
            ])
            ->assertStatus(404);

        // Verify ZERO mutations to Tenant B's sale item, payment, or either tenant's stock
        $this->saleItemB->refresh();
        $this->paymentB->refresh();
        $this->assertEquals(2, (float) $this->saleItemB->quantity);
        $this->assertEquals(50, (float) $this->saleItemB->sub_total);
        $this->assertEquals($this->saleB->id, $this->paymentB->sale_id);
        $this->assertEquals(20, (float) $this->paymentB->amount);
        $this->assertEquals($stockBBefore, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->whB->id)->where('product_id', $this->productB->id)->value('quantity'));
        $this->assertEquals($stockABefore, (float) ManageStock::withoutGlobalScope('tenant')->where('warehouse_id', $this->whA->id)->where('product_id', $this->productA->id)->value('quantity'));
    }

    public function test_tenant_a_cannot_access_or_mutate_tenant_b_purchase_child_records(): void
    {
        // 1. Read Tenant B purchase -> 404
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/purchases/{$this->purchaseB->id}")
            ->assertStatus(404);

        // 2. Combine Tenant A parent purchase with Tenant B's purchase_item_id -> 404 & unchanged
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/purchases/{$this->purchaseA->id}", [
                'date' => Carbon::today()->toDateString(),
                'supplier_id' => $this->supplierA->id,
                'warehouse_id' => $this->whA->id,
                'tax_rate' => 0,
                'discount' => 0,
                'shipping' => 0,
                'status' => Purchase::RECEIVED,
                'purchase_items' => [[
                    'purchase_item_id' => $this->purchaseItemB->id,
                    'product_id' => $this->productA->id,
                    'product_cost' => 1,
                    'net_unit_cost' => 1,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'purchase_unit' => $this->productA->purchase_unit,
                    'quantity' => 9,
                    'sub_total' => 9,
                ]],
            ])
            ->assertStatus(404);

        $this->purchaseItemB->refresh();
        $this->assertEquals(3, (float) $this->purchaseItemB->quantity);
        $this->assertEquals(30, (float) $this->purchaseItemB->sub_total);
    }

    public function test_tenant_a_cannot_delete_tenant_b_media_or_download_tenant_b_protected_file(): void
    {
        // 1. Delete Tenant B's product media -> 404 & media still exists on DB and disk
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/products-image-delete/{$this->mediaB->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('media', ['id' => $this->mediaB->id]);
        $this->assertFileExists($this->mediaB->getPath());

        // 2. Access Tenant B's protected file -> 403
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/private-files/' . $this->protectedFileB)
            ->assertStatus(403);
    }

    public function test_authorized_same_tenant_and_super_admin_operations_continue_working(): void
    {
        // 1. Tenant A reads its own sale payments -> 200
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson("/api/sales/{$this->saleA->id}/payments")
            ->assertStatus(200);

        // 2. Tenant A updates its own sale payment -> 200
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/sales/{$this->paymentA->id}/payment", [
                'amount' => 30,
                'payment_type' => $this->payMethodA->id,
                'payment_date' => Carbon::today()->toDateString(),
            ])
            ->assertStatus(200);

        $this->paymentA->refresh();
        $this->assertEquals(30, (float) $this->paymentA->amount);

        // 3. Tenant A updates its own sale item -> 200
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/sales/{$this->saleA->id}", [
                'date' => Carbon::today()->toDateString(),
                'customer_id' => $this->customerA->id,
                'warehouse_id' => $this->whA->id,
                'tax_rate' => 0,
                'discount' => 0,
                'shipping' => 0,
                'payment_status' => Sale::PARTIAL_PAID,
                'status' => Sale::COMPLETED,
                'sale_items' => [[
                    'sale_item_id' => $this->saleItemA->id,
                    'product_id' => $this->productA->id,
                    'product_price' => 25,
                    'net_unit_price' => 25,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'sale_unit' => $this->productA->sale_unit,
                    'quantity' => 3,
                    'sub_total' => 75,
                ]],
                'payment_details' => [[
                    'id' => $this->paymentA->id,
                    'amount' => 30,
                    'payment_type' => ['value' => $this->payMethodA->id],
                    'date' => Carbon::today()->toDateString(),
                ]],
            ])
            ->assertStatus(200);

        $this->saleItemA->refresh();
        $this->assertEquals(3, (float) $this->saleItemA->quantity);

        // 4. Tenant A updates its own purchase item -> 200
        $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/purchases/{$this->purchaseA->id}", [
                'date' => Carbon::today()->toDateString(),
                'supplier_id' => $this->supplierA->id,
                'warehouse_id' => $this->whA->id,
                'tax_rate' => 0,
                'discount' => 0,
                'shipping' => 0,
                'status' => Purchase::RECEIVED,
                'purchase_items' => [[
                    'purchase_item_id' => $this->purchaseItemA->id,
                    'product_id' => $this->productA->id,
                    'product_cost' => 10,
                    'net_unit_cost' => 10,
                    'tax_type' => 1,
                    'tax_value' => 0,
                    'tax_amount' => 0,
                    'discount_type' => 2,
                    'discount_value' => 0,
                    'discount_amount' => 0,
                    'purchase_unit' => $this->productA->purchase_unit,
                    'quantity' => 4,
                    'sub_total' => 40,
                ]],
            ])
            ->assertStatus(200);

        $this->purchaseItemA->refresh();
        $this->assertEquals(4, (float) $this->purchaseItemA->quantity);

        // 5. Tenant A deletes its own product media -> 200
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/products-image-delete/{$this->mediaA->id}")
            ->assertStatus(200);
        $this->assertDatabaseMissing('media', ['id' => $this->mediaA->id]);

        // 6. Tenant A deletes its own sale payment -> 200
        $this->actingAs($this->adminA, 'sanctum')
            ->deleteJson("/api/sales/{$this->paymentA->id}/payment")
            ->assertStatus(200);
        $this->assertDatabaseMissing('sales_payments', ['id' => $this->paymentA->id]);

        // 7. Authorized Super Admin operations continue working -> 200
        $superAdmin = User::withoutGlobalScope('tenant')
            ->whereHas('roles', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->first();
        $this->assertNotNull($superAdmin);
        $this->actingAs($superAdmin, 'sanctum')
            ->getJson('/api/sadmin/admin-users')
            ->assertStatus(200);
    }
}
