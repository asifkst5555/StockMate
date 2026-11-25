<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Currency;
use App\Models\MainProduct;
use App\Models\ManageStock;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserStore;
use App\Models\Warehouse;
use App\Services\BarcodeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Tests\TestCase;

class BarcodeWorkflowTest extends TestCase
{
    private string $token;
    private string $tenantAId;
    private string $tenantBId;

    private ?User $adminA = null;
    private ?User $adminB = null;
    private ?Store $storeA = null;
    private ?Store $storeB = null;
    private ?Plan $plan = null;
    private ?Warehouse $warehouseA = null;
    private ?Warehouse $warehouseB = null;
    private ?Brand $brandA = null;
    private ?Brand $brandB = null;
    private ?ProductCategory $categoryA = null;
    private ?ProductCategory $categoryB = null;
    private ?Unit $unitA = null;
    private ?Unit $unitB = null;
    private ?MainProduct $mainProductA = null;
    private ?MainProduct $mainProductB = null;
    private ?Product $productA = null;
    private ?Product $productB = null;

    /** @var array<int, Product> */
    private array $createdProducts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Str::lower(Str::random(8));
        $this->tenantAId = 'phb_tA_' . $this->token;
        $this->tenantBId = 'phb_tB_' . $this->token;

        DB::table('tenants')->insert([
            ['id' => $this->tenantAId, 'store_id' => "phb_sA_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
            ['id' => $this->tenantBId, 'store_id' => "phb_sB_{$this->token}", 'created_at' => now(), 'updated_at' => now(), 'data' => '{}'],
        ]);

        $this->adminA = $this->makeUser("PhBAdminA_{$this->token}", "phb.a.{$this->token}@stockmate.test", $this->tenantAId, Role::ADMIN);
        $this->adminB = $this->makeUser("PhBAdminB_{$this->token}", "phb.b.{$this->token}@stockmate.test", $this->tenantBId, Role::ADMIN);

        $this->storeA = Store::create(['name' => "PhB Store A {$this->token}", 'tenant_id' => $this->tenantAId, 'user_id' => $this->adminA->id, 'status' => 1]);
        $this->storeB = Store::create(['name' => "PhB Store B {$this->token}", 'tenant_id' => $this->tenantBId, 'user_id' => $this->adminB->id, 'status' => 1]);

        UserStore::create(['user_id' => $this->adminA->id, 'store_id' => $this->storeA->id]);
        UserStore::create(['user_id' => $this->adminB->id, 'store_id' => $this->storeB->id]);

        $currency = Currency::where('code', 'USD')->first() ?: Currency::first();
        $this->plan = Plan::create([
            'name' => "PhB Plan {$this->token}",
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
            'name' => "PhB Wh A {$this->token}",
            'phone' => '1111111111',
            'country' => 'US',
            'city' => 'NYC',
            'email' => "phb.wha.{$this->token}@stockmate.test",
            'zip_code' => '10001',
        ]);
        $this->warehouseB = $this->createWithoutTenantScope(Warehouse::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "PhB Wh B {$this->token}",
            'phone' => '2222222222',
            'country' => 'US',
            'city' => 'LA',
            'email' => "phb.whb.{$this->token}@stockmate.test",
            'zip_code' => '90001',
        ]);

        $this->brandA = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantAId, 'name' => "PhB Brand A {$this->token}"]);
        $this->brandB = $this->createWithoutTenantScope(Brand::class, ['tenant_id' => $this->tenantBId, 'name' => "PhB Brand B {$this->token}"]);

        $this->categoryA = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantAId, 'name' => "PhB Cat A {$this->token}"]);
        $this->categoryB = $this->createWithoutTenantScope(ProductCategory::class, ['tenant_id' => $this->tenantBId, 'name' => "PhB Cat B {$this->token}"]);

        $this->unitA = Unit::first();
        $this->unitB = $this->unitA;

        $this->mainProductA = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantAId,
            'name' => "PhB Main A {$this->token}",
            'code' => "MAIN-A-{$this->token}",
            'product_unit' => (string) ($this->unitA?->id ?? 1),
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);
        $this->mainProductB = $this->createWithoutTenantScope(MainProduct::class, [
            'tenant_id' => $this->tenantBId,
            'name' => "PhB Main B {$this->token}",
            'code' => "MAIN-B-{$this->token}",
            'product_unit' => (string) ($this->unitB?->id ?? 1),
            'product_type' => MainProduct::SINGLE_PRODUCT,
        ]);

        $this->productA = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $this->mainProductA->id,
            'name' => "PhB Product A {$this->token}",
            'code' => "BAR-A-{$this->token}",
            'product_code' => "SKU-A-{$this->token}",
            'product_category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 10.00,
            'product_price' => 25.00,
            'product_unit' => (string) ($this->unitA?->id ?? 1),
            'sale_unit' => (string) ($this->unitA?->id ?? 1),
            'purchase_unit' => (string) ($this->unitA?->id ?? 1),
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);
        $this->createdProducts[] = $this->productA;

        $this->productB = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantBId,
            'main_product_id' => $this->mainProductB->id,
            'name' => "PhB Product B {$this->token}",
            'code' => "BAR-B-{$this->token}",
            'product_code' => "SKU-B-{$this->token}",
            'product_category_id' => $this->categoryB->id,
            'brand_id' => $this->brandB->id,
            'product_cost' => 12.00,
            'product_price' => 30.00,
            'product_unit' => (string) ($this->unitB?->id ?? 1),
            'sale_unit' => (string) ($this->unitB?->id ?? 1),
            'purchase_unit' => (string) ($this->unitB?->id ?? 1),
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);
        $this->createdProducts[] = $this->productB;

        $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantAId,
            'warehouse_id' => $this->warehouseA->id,
            'product_id' => $this->productA->id,
            'quantity' => 12,
        ]);
        $this->createWithoutTenantScope(ManageStock::class, [
            'tenant_id' => $this->tenantBId,
            'warehouse_id' => $this->warehouseB->id,
            'product_id' => $this->productB->id,
            'quantity' => 7,
        ]);
    }

    protected function tearDown(): void
    {
        $barcodeService = app(BarcodeService::class);
        foreach ($this->createdProducts as $product) {
            if ($product && $product->id) {
                $barcodeService->deleteProductBarcodePng($product);
            }
        }

        $productIds = array_filter(array_map(fn ($p) => $p?->id, $this->createdProducts));
        if (!empty($productIds)) {
            ManageStock::whereIn('product_id', $productIds)->delete();
            DB::table('products')->whereIn('id', $productIds)->delete();
        }

        DB::table('main_products')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
        DB::table('warehouses')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
        DB::table('brands')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();
        DB::table('product_categories')->whereIn('tenant_id', [$this->tenantAId, $this->tenantBId])->delete();

        $userIds = array_filter([$this->adminA?->id, $this->adminB?->id]);
        if (!empty($userIds)) {
            Subscription::whereIn('user_id', $userIds)->delete();
            UserStore::whereIn('user_id', $userIds)->delete();
            Store::whereIn('user_id', $userIds)->delete();
            DB::table('model_has_roles')->whereIn('model_id', $userIds)->delete();
            User::whereIn('id', $userIds)->forceDelete();
        }
        if ($this->plan) {
            $this->plan->delete();
        }
        DB::table('tenants')->whereIn('id', [$this->tenantAId, $this->tenantBId])->delete();

        parent::tearDown();
    }

    private function makeUser(string $name, string $email, string $tenantId, string $roleName): User
    {
        $user = User::withoutGlobalScope('tenant')->create([
            'first_name' => $name,
            'last_name' => 'Test',
            'email' => $email,
            'phone' => '92313' . random_int(1000000, 9999999),
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

    public function test_barcode_service_validates_all_five_formats_and_rejects_invalid_values(): void
    {
        $service = app(BarcodeService::class);

        // Valid values across all 5 supported formats
        $validCases = [
            [Product::CODE128, 'SKU-12345_AB'],
            [Product::CODE39, 'ABC-123 $/.+%'],
            [Product::EAN8, '96385074'],       // valid GS1 check digit 4
            [Product::UPC, '036000291452'],    // valid GS1 check digit 2
            [Product::EAN13, '5901234123457'], // valid GS1 check digit 7
        ];

        foreach ($validCases as [$symbol, $code]) {
            $check = $service->validate($code, $symbol);
            $this->assertTrue($check['valid'], "Expected valid {$code} for symbol {$symbol}: " . ($check['error'] ?? ''));
            $png = $service->generatePngBinary($code, $symbol);
            $this->assertStringStartsWith("\x89PNG", $png, "Expected PNG header for {$code}");
        }

        // Invalid values must be rejected with clear validation messages
        $invalidCases = [
            [Product::CODE128, ''],
            [Product::CODE39, 'BAD@CHAR!'],
            [Product::EAN8, '96385075'],       // wrong check digit
            [Product::EAN8, '12345'],          // wrong length
            [Product::UPC, '036000291453'],    // wrong check digit
            [Product::EAN13, '5901234123458'], // wrong check digit
            [Product::EAN13, '590123412345A'], // non-numeric
            [99, 'ABC123'],                    // unknown symbol
        ];

        foreach ($invalidCases as [$symbol, $code]) {
            $check = $service->validate($code, $symbol);
            $this->assertFalse($check['valid'], "Expected invalid {$code} for symbol {$symbol}");
            $this->assertNotNull($check['error']);
            $threw = false;
            try {
                $service->generatePngBinary($code, $symbol);
            } catch (UnprocessableEntityHttpException $e) {
                $threw = true;
            }
            $this->assertTrue($threw, "Expected UnprocessableEntityHttpException for invalid {$code} (symbol {$symbol})");
        }
    }

    public function test_standalone_barcode_endpoint_requires_auth_and_validates_symbol(): void
    {
        // Unauthenticated must be 401
        $this->postJson('/api/products/generate-barcode', ['code' => 'TEST-001'])
            ->assertStatus(401);

        // Authenticated valid CODE128
        $res128 = $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/products/generate-barcode', [
                'code' => 'SKU-9988',
                'barcode_symbol' => Product::CODE128,
            ]);
        $res128->assertStatus(200);
        $dataUri = $res128->json('data');
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
        $decoded = base64_decode(substr($dataUri, strlen('data:image/png;base64,')));
        $this->assertStringStartsWith("\x89PNG", $decoded);

        // Authenticated valid EAN13
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/products/generate-barcode', [
                'code' => '5901234123457',
                'barcode_symbol' => Product::EAN13,
            ])
            ->assertStatus(200);

        // Authenticated invalid EAN13 check digit -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson('/api/products/generate-barcode', [
                'code' => '5901234123458',
                'barcode_symbol' => Product::EAN13,
            ])
            ->assertStatus(422);
    }

    public function test_product_barcode_url_unified_and_ensure_barcode_is_idempotent_and_tenant_isolated(): void
    {
        $service = app(BarcodeService::class);
        $disk = $service->storageDisk();
        $relPath = $service->relativePathForProduct($this->productA);

        $this->assertFalse(Storage::disk($disk)->exists($relPath));
        $this->assertSame('', $this->productA->barcode_image_url);

        // First ensure-barcode generates PNG
        $res1 = $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/products/{$this->productA->id}/ensure-barcode");
        $res1->assertStatus(200)
            ->assertJsonPath('data.status', 'generated');

        $this->assertTrue(Storage::disk($disk)->exists($relPath));
        $this->assertStringStartsWith("\x89PNG", Storage::disk($disk)->get($relPath));
        $this->assertStringContainsString('barcode-PR_' . $this->productA->id . '.png?v=', $res1->json('data.barcode_url'));

        // Unified URL on model accessor and prepareAttributes
        $this->productA->refresh();
        $this->assertSame($this->productA->barcode_image_url, $this->productA->prepareAttributes()['barcode_url']);
        $this->assertNotEmpty($this->productA->barcode_image_url);

        // Second ensure-barcode preserves existing PNG without overwriting
        $res2 = $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/products/{$this->productA->id}/ensure-barcode");
        $res2->assertStatus(200)
            ->assertJsonPath('data.status', 'skipped_existing');

        // Cross-tenant ensure-barcode must be rejected with 404
        $this->actingAs($this->adminA, 'sanctum')
            ->postJson("/api/products/{$this->productB->id}/ensure-barcode")
            ->assertStatus(404);
    }

    public function test_backfill_command_is_dry_run_safe_tenant_scoped_and_idempotent(): void
    {
        $service = app(BarcodeService::class);
        $disk = $service->storageDisk();
        $pathA = $service->relativePathForProduct($this->productA);
        $pathB = $service->relativePathForProduct($this->productB);

        $this->assertFalse(Storage::disk($disk)->exists($pathA));
        $this->assertFalse(Storage::disk($disk)->exists($pathB));

        // 1. Dry run must not write any files
        $exitDry = Artisan::call('stockmate:barcodes:backfill', [
            '--dry-run' => true,
            '--tenant' => $this->tenantAId,
            '--batch' => 10,
        ]);
        $this->assertSame(0, $exitDry);
        $this->assertFalse(Storage::disk($disk)->exists($pathA));
        $this->assertFalse(Storage::disk($disk)->exists($pathB));

        // 2. Tenant-scoped run generates ONLY Tenant A's barcode PNG
        $exitRun = Artisan::call('stockmate:barcodes:backfill', [
            '--tenant' => $this->tenantAId,
            '--batch' => 10,
        ]);
        $this->assertSame(0, $exitRun);
        $this->assertTrue(Storage::disk($disk)->exists($pathA));
        $this->assertStringStartsWith("\x89PNG", Storage::disk($disk)->get($pathA));
        $this->assertFalse(Storage::disk($disk)->exists($pathB));

        // 3. Re-run is idempotent and does not alter product SKU/price/code
        $originalBytes = Storage::disk($disk)->get($pathA);
        $exitRerun = Artisan::call('stockmate:barcodes:backfill', [
            '--tenant' => $this->tenantAId,
            '--batch' => 10,
        ]);
        $this->assertSame(0, $exitRerun);
        $this->assertSame($originalBytes, Storage::disk($disk)->get($pathA));
        $this->productA->refresh();
        $this->assertSame("BAR-A-{$this->token}", $this->productA->code);
        $this->assertSame("SKU-A-{$this->token}", $this->productA->product_code);
        $this->assertEquals(25.00, (float) $this->productA->product_price);
    }

    public function test_pos_exact_barcode_lookup_supports_code_and_product_code_with_warehouse_and_tenant_isolation(): void
    {
        // Unauthenticated rejected
        $this->getJson('/api/products/by-barcode?barcode=BAR-A-' . $this->token)
            ->assertStatus(401);

        // Empty barcode -> 422
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products/by-barcode?barcode=')
            ->assertStatus(422);

        // Exact lookup by code with warehouse_id returns product + warehouse stock
        $resCode = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products/by-barcode?barcode=' . urlencode("BAR-A-{$this->token}") . '&warehouse_id=' . $this->warehouseA->id);
        $resCode->assertStatus(200)
            ->assertJsonPath('data.id', $this->productA->id)
            ->assertJsonPath('data.attributes.code', "BAR-A-{$this->token}")
            ->assertJsonPath('data.attributes.stock.quantity', 12);

        // Exact lookup by product_code also resolves the product
        $resProductCode = $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products/by-barcode?barcode=' . urlencode("SKU-A-{$this->token}") . '&warehouse_id=' . $this->warehouseA->id);
        $resProductCode->assertStatus(200)
            ->assertJsonPath('data.id', $this->productA->id);

        // Completely unknown barcode must return 404
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products/by-barcode?barcode=UNKNOWN-BARCODE-999999&warehouse_id=' . $this->warehouseA->id)
            ->assertStatus(404);

        // Partial barcode must NOT match -> 404
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products/by-barcode?barcode=' . urlencode("BAR-A-") . '&warehouse_id=' . $this->warehouseA->id)
            ->assertStatus(404);

        // Cross-tenant barcode must NOT match -> 404
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products/by-barcode?barcode=' . urlencode("BAR-B-{$this->token}") . '&warehouse_id=' . $this->warehouseA->id)
            ->assertStatus(404);

        // Cross-tenant warehouse_id must be rejected -> 404
        $this->actingAs($this->adminA, 'sanctum')
            ->getJson('/api/products/by-barcode?barcode=' . urlencode("BAR-A-{$this->token}") . '&warehouse_id=' . $this->warehouseB->id)
            ->assertStatus(404);
    }

    public function test_backfill_command_handles_per_product_exception_and_allows_safe_retry(): void
    {
        $productA2 = $this->createWithoutTenantScope(Product::class, [
            'tenant_id' => $this->tenantAId,
            'main_product_id' => $this->mainProductA->id,
            'name' => "PhB Product A2 {$this->token}",
            'code' => "BAR-A2-{$this->token}",
            'product_code' => "SKU-A2-{$this->token}",
            'product_category_id' => $this->categoryA->id,
            'brand_id' => $this->brandA->id,
            'product_cost' => 15.00,
            'product_price' => 35.00,
            'product_unit' => (string) ($this->unitA?->id ?? 1),
            'sale_unit' => (string) ($this->unitA?->id ?? 1),
            'purchase_unit' => (string) ($this->unitA?->id ?? 1),
            'barcode_symbol' => Product::CODE128,
            'tax_type' => 1,
            'order_tax' => 0,
        ]);
        $this->createdProducts[] = $productA2;

        $realService = new BarcodeService();
        $disk = $realService->storageDisk();
        $pathA1 = $realService->relativePathForProduct($this->productA);
        $pathA2 = $realService->relativePathForProduct($productA2);
        $pathB = $realService->relativePathForProduct($this->productB);

        // Bind a fault-injecting BarcodeService that throws ONLY on productA, simulating a transient I/O error
        $failingProductId = $this->productA->id;
        $faultyService = new class($failingProductId) extends BarcodeService {
            public function __construct(private int $failId) {}

            public function ensureProductBarcodePng(Product $product, bool $forceRegenerate = false, bool $dryRun = false): array
            {
                if ($product->id === $this->failId) {
                    throw new \RuntimeException('Simulated transient storage write failure on /var/www/html/secret');
                }

                return parent::ensureProductBarcodePng($product, $forceRegenerate, $dryRun);
            }
        };
        $this->app->instance(BarcodeService::class, $faultyService);

        try {
            // Run 1: productA throws, productA2 MUST still succeed, exit code = FAILURE (1)
            $exitFirst = Artisan::call('stockmate:barcodes:backfill', [
                '--tenant' => $this->tenantAId,
                '--batch' => 10,
            ]);
            $outputFirst = Artisan::output();

            $this->assertSame(1, $exitFirst);
            $this->assertStringContainsString('scanned=2, generated=1, would_generate=0, skipped_existing=0, failed=1', $outputFirst);
            $this->assertStringContainsString("Failed product IDs: {$this->productA->id}", $outputFirst);
            $this->assertFalse(Storage::disk($disk)->exists($pathA1));
            $this->assertTrue(Storage::disk($disk)->exists($pathA2));
            $this->assertFalse(Storage::disk($disk)->exists($pathB));
        } finally {
            // Restore real BarcodeService
            $this->app->instance(BarcodeService::class, $realService);
        }

        // Run 2 (Recovery retry): skips productA2, generates previously failed productA, exit code = SUCCESS (0)
        $bytesA2BeforeRetry = Storage::disk($disk)->get($pathA2);
        $exitRetry = Artisan::call('stockmate:barcodes:backfill', [
            '--tenant' => $this->tenantAId,
            '--batch' => 10,
        ]);
        $outputRetry = Artisan::output();

        $this->assertSame(0, $exitRetry);
        $this->assertStringContainsString('scanned=2, generated=1, would_generate=0, skipped_existing=1, failed=0', $outputRetry);
        $this->assertTrue(Storage::disk($disk)->exists($pathA1));
        $this->assertStringStartsWith("\x89PNG", Storage::disk($disk)->get($pathA1));
        $this->assertSame($bytesA2BeforeRetry, Storage::disk($disk)->get($pathA2));
        $this->assertFalse(Storage::disk($disk)->exists($pathB));

        // Tenant isolation on --product-id retry: passing Tenant B's ID with --tenant=A must process 0 rows
        $exitCrossTenant = Artisan::call('stockmate:barcodes:backfill', [
            '--tenant' => $this->tenantAId,
            '--product-id' => [$this->productB->id],
        ]);
        $this->assertSame(0, $exitCrossTenant);
        $this->assertStringContainsString('scanned=0', Artisan::output());
        $this->assertFalse(Storage::disk($disk)->exists($pathB));
    }
}
