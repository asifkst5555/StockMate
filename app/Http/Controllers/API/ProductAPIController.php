<?php

namespace App\Http\Controllers\API;

use App\Exports\ProductExcelExport;
use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;
use App\Imports\ProductImport;
use App\Models\MainProduct;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\SaleItem;
use App\Models\VariationProduct;
use App\Models\Warehouse;
use App\Repositories\ProductRepository;
use App\Services\BarcodeService;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class ProductAPIController extends AppBaseController
{
    /** @var ProductRepository */
    private $productRepository;

    public function __construct(ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public function index(Request $request): ProductCollection
    {
        $perPage = getPageSize($request);
        $products = $this->productRepository;

        if ($request->get('product_unit')) {
            $products->where('product_unit', $request->get('product_unit'));
        }

        if ($request->get('warehouse_id') && $request->get('warehouse_id') != 'null') {
            $warehouseId = $request->get('warehouse_id');
            $products->whereHas('stock', function ($q) use ($warehouseId) {
                $q->where('manage_stocks.warehouse_id', $warehouseId);
            })->with([
                'stock' => function (HasOne $query) use ($warehouseId) {
                    $query->where('manage_stocks.warehouse_id', $warehouseId);
                },
            ]);
        }

        if (isset($request->filter['search'])) {
            $search = $request->filter['search'];
            $products->whereHas('mainProduct', function ($query) use ($search) {
                $query->where('name', 'like', '%' . $search . '%');
            });
        }

        $products = $products->paginate($perPage);
        ProductResource::usingWithCollection();

        return new ProductCollection($products);
    }

    /**
     * @return ProductResource|JsonResponse
     */
    public function store(CreateProductRequest $request)
    {
        $input = $request->all();

        if ($input['main_product_id']) {
            $mainProduct = MainProduct::find($input['main_product_id']);
            if ($mainProduct->product_type == MainProduct::SINGLE_PRODUCT) {
                return $this->sendError('You can add variations for single type product');
            }
        }

        if ($input['barcode_symbol'] == Product::EAN8 && strlen($input['code']) != 7) {
            return $this->sendError('Please enter 7 digit code');
        }

        if ($input['barcode_symbol'] == Product::UPC && strlen($input['code']) != 11) {
            return $this->sendError(' Please enter 11 digit code');
        }

        $product = $this->productRepository->storeProduct($input);

        VariationProduct::create([
            'product_id' => $product->id,
            'variation_id' => $input['variation_id'],
            'variation_type_id' => $input['variation_type'],
            'main_product_id' => $input['main_product_id'],
        ]);

        return new ProductResource($product);
    }

    public function show($id): ProductResource
    {
        $product = $this->productRepository->find($id);

        return new ProductResource($product);
    }

    public function update(UpdateProductRequest $request, $id): ProductResource
    {
        $input = $request->all();

        $product = $this->productRepository->updateProduct($input, $id);

        if ($product->mainProduct && $product->mainProduct->product_type == MainProduct::SINGLE_PRODUCT) {
            $product->mainProduct->code = $product->code;
            $product->mainProduct->save();
        }

        return new ProductResource($product);
    }

    public function destroy($id): JsonResponse
    {
        $product = Product::whereKey($id)->firstOrFail();

        $linkedItemModels = [
            PurchaseItem::class,
            SaleItem::class,
            \App\Models\PurchaseReturnItem::class,
            \App\Models\SaleReturnItem::class,
            \App\Models\AdjustmentItem::class,
            \App\Models\TransferItem::class,
            \App\Models\QuotationItem::class,
            \App\Models\HoldItem::class,
            \App\Models\StockMovement::class,
        ];
        $hasLinkedRecords = canDelete($linkedItemModels, 'product_id', $product->id);
        $hasActiveStock = \App\Models\ManageStock::where('product_id', $product->id)
            ->where('quantity', '>', 0)
            ->exists();

        if ($hasLinkedRecords || $hasActiveStock) {
            return $this->sendError(__('messages.error.product_cant_deleted'));
        }

        $mainProduct = MainProduct::withCount('products')->find($product->main_product_id);

        if ($mainProduct && $mainProduct->product_type == MainProduct::VARIATION_PRODUCT && $mainProduct->products_count <= 1) {
            return $this->sendError('You can not delete last variation product');
        }

        if (File::exists(Storage::path('product_barcode/barcode-PR_' . $product->id . '.png'))) {
            File::delete(Storage::path('product_barcode/barcode-PR_' . $product->id . '.png'));
        }

        VariationProduct::where('product_id', $product->id)->delete();

        $this->productRepository->delete($product->id);

        return $this->sendSuccess('Product deleted successfully');
    }

    public function productImageDelete($mediaId): JsonResponse
    {
        // SEC-A2: Ensure media belongs to a Product/MainProduct owned by the current tenant
        $media = Media::whereKey($mediaId)
            ->whereIn('model_type', [MainProduct::class, Product::class])
            ->first();

        if (! $media) {
            return $this->sendError('Product image not found.', 404);
        }

        $ownerClass = $media->model_type;
        $owner = $ownerClass::whereKey($media->model_id)->first();
        if (! $owner) {
            return $this->sendError('Product image not found.', 404);
        }

        $media->delete();

        return $this->sendSuccess('Product image deleted successfully');
    }

    public function importProducts(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
        ]);

        Excel::import(new ProductImport, $request->file('file'));

        return $this->sendSuccess('Products imported successfully');
    }

    public function getProductExportExcel(Request $request): JsonResponse
    {
        $data['product_excel_url'] = \App\Services\ProtectedExportService::storeExcel(
            new ProductExcelExport,
            'product-excel-export'
        );

        return $this->sendResponse($data, 'Product retrieved successfully');
    }

    public function getAllProducts()
    {
        $products = Product::all();
        $data = [];

        foreach ($products as $product) {
            $data[] = [
                'id' => $product->id,
                'name' => $product->name,
            ];
        }

        return $this->sendResponse($data, 'Products retrieve successfully.');
    }

    public function generateStandaloneBarcode(Request $request): JsonResponse
    {
        $code = trim((string) $request->input('code', ''));
        $symbol = (int) $request->input('barcode_symbol', Product::CODE128);

        if ($code === '') {
            return $this->sendError('Barcode value is required.', 422);
        }

        try {
            $barcode = $this->productRepository->generateBarcodeBase64($code, $symbol);
        } catch (UnprocessableEntityHttpException $e) {
            return $this->sendError($e->getMessage(), 422);
        }

        return $this->sendResponse($barcode, 'Barcode generated successfully.');
    }

    /**
     * Exact barcode lookup for POS scanner workflows (tenant & warehouse scoped).
     */
    public function findByBarcode(Request $request): ProductResource|JsonResponse
    {
        $barcode = trim((string) $request->query('barcode', ''));
        $warehouseId = $request->query('warehouse_id');

        if ($barcode === '') {
            return $this->sendError('Barcode is required.', 422);
        }

        $hasWarehouse = $warehouseId !== null && $warehouseId !== '' && $warehouseId !== 'null';
        if ($hasWarehouse) {
            if (!is_numeric($warehouseId) || !Warehouse::where('id', (int) $warehouseId)->exists()) {
                return $this->sendError('Warehouse not found.', 404);
            }
        }

        $query = Product::query()
            ->where(function ($q) use ($barcode) {
                $q->where('code', $barcode)
                    ->orWhere('product_code', $barcode);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$barcode]);

        if ($hasWarehouse) {
            $wid = (int) $warehouseId;
            $query->with([
                'stock' => function (HasOne $q) use ($wid) {
                    $q->where('manage_stocks.warehouse_id', $wid);
                },
            ]);
        }

        $product = $query->first();

        if (!$product) {
            return $this->sendError('Product not found for barcode.', 404);
        }

        return new ProductResource($product);
    }

    /**
     * Ensure a product's barcode PNG exists on the active storage disk.
     */
    public function ensureBarcode(int $id): JsonResponse
    {
        $product = Product::find($id);

        if (!$product) {
            return $this->sendError('Product not found.', 404);
        }

        $barcodeService = app(BarcodeService::class);
        $result = $barcodeService->ensureProductBarcodePng($product, false, false);

        if ($result['status'] === 'failed_validation') {
            return $this->sendError($result['error'] ?? 'Invalid product barcode.', 422);
        }

        return $this->sendResponse([
            'status' => $result['status'],
            'barcode_url' => $barcodeService->resolveProductBarcodeUrl($product),
        ], 'Barcode ready.');
    }
}
