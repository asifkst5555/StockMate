<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreatePurchaseReturnRequest;
use App\Http\Requests\UpdatePurchaseReturnRequest;
use App\Http\Resources\PurchaseReturnCollection;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Repositories\PurchaseReturnRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PurchaseReturnAPIController extends AppBaseController
{
    /** @var PurchaseReturnRepository */
    private $purchaseReturnRepository;

    /**
     * PurchaseReturnAPIController constructor.
     */
    public function __construct(PurchaseReturnRepository $purchaseReturnRepository)
    {
        $this->purchaseReturnRepository = $purchaseReturnRepository;
    }

    public function index(Request $request): PurchaseReturnCollection
    {
        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $supplier = (Supplier::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $purchasesReturn = $this->purchaseReturnRepository;
        if ($supplier || $warehouse) {
            $purchasesReturn->whereHas('supplier', function (Builder $q) use ($search, $supplier) {
                if ($supplier) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            })->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }

        if ($request->get('start_date') && $request->get('end_date')) {
            $purchasesReturn->whereBetween(
                'date',
                [$request->get('start_date'), $request->get('end_date')]
            );
        }

        if ($request->get('warehouse_id')) {
            $purchasesReturn->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('status')) {
            $purchasesReturn->where('status', $request->get('status'));
        }

        $purchasesReturn = $purchasesReturn->paginate($perPage);
        PurchaseReturnResource::usingWithCollection();

        return new PurchaseReturnCollection($purchasesReturn);
    }

    public function store(CreatePurchaseReturnRequest $request): PurchaseReturnResource
    {
        $input = $request->all();
        $purchaseReturn = $this->purchaseReturnRepository->storePurchaseReturn($input);

        return new PurchaseReturnResource($purchaseReturn);
    }

    public function show($id): PurchaseReturnResource
    {
        $purchaseReturn = $this->purchaseReturnRepository->find($id);

        return new PurchaseReturnResource($purchaseReturn);
    }

    public function edit(PurchaseReturn $purchasesReturn): PurchaseReturnResource
    {
        $purchasesReturn = $purchasesReturn->load('purchaseReturnItems.product.stocks', 'warehouse');

        return new PurchaseReturnResource($purchasesReturn);
    }

    public function editByPurchase($purchaseId)
    {
        $purchaseReturn = PurchaseReturn::where('purchase_id', $purchaseId)->first();
        if (empty($purchaseReturn)) {
            return $this->sendError('Purchase Return is not created');
        }
        $purchaseReturn = $purchaseReturn->load('purchaseReturnItems', 'purchaseReturnItems.product.stocks', 'warehouse');

        return new PurchaseReturnResource($purchaseReturn);
    }

    public function update(UpdatePurchaseReturnRequest $request, $id): PurchaseReturnResource
    {
        $input = $request->all();
        $purchaseReturn = $this->purchaseReturnRepository->updatePurchaseReturn($input, $id);

        return new PurchaseReturnResource($purchaseReturn);
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();
            // S1 lock order: Eligible Purchases (ordered by ID) -> PurchaseReturn -> ManageStock
            $meta = PurchaseReturn::whereKey($id)->first(['id', 'purchase_id', 'supplier_id', 'warehouse_id']);
            if ($meta) {
                $lockPurchaseIds = Purchase::where('supplier_id', $meta->supplier_id)
                    ->where('warehouse_id', $meta->warehouse_id)
                    ->where('status', Purchase::RECEIVED)
                    ->pluck('id')
                    ->all();
                if ($meta->purchase_id) {
                    $lockPurchaseIds[] = (int) $meta->purchase_id;
                }
                $lockPurchaseIds = array_values(array_unique(array_map('intval', $lockPurchaseIds)));
                sort($lockPurchaseIds);
                if (! empty($lockPurchaseIds)) {
                    Purchase::whereIn('id', $lockPurchaseIds)->orderBy('id')->lockForUpdate()->get();
                }
            }

            $purchaseReturn = PurchaseReturn::with('purchaseReturnItems')->where('id', $id)->lockForUpdate()->firstOrFail();

            if ((int) $purchaseReturn->status === PurchaseReturn::COMPLETED) {
                /** @var \App\Services\StockMovementService $stockService */
                $stockService = app(\App\Services\StockMovementService::class);
                $pairs = [];
                foreach ($purchaseReturn->purchaseReturnItems as $item) {
                    $pairs[] = ['warehouse_id' => (int) $purchaseReturn->warehouse_id, 'product_id' => (int) $item->product_id];
                }
                $stockService->lockStockRows($pairs);

                foreach ($purchaseReturn->purchaseReturnItems as $purchaseReturnItem) {
                    $stockService->reverse(
                        (int) $purchaseReturn->warehouse_id,
                        (int) $purchaseReturnItem->product_id,
                        (float) $purchaseReturnItem->quantity,
                        'purchase_return_delete_reversal',
                        PurchaseReturn::class,
                        (int) $purchaseReturn->id,
                        isset($purchaseReturnItem->product_cost) ? (float) $purchaseReturnItem->product_cost : null,
                        'Purchase return deletion reversal (' . ($purchaseReturn->reference_code ?? ('#' . $purchaseReturn->id)) . ')'
                    );
                }
            }

            $purchaseId = $purchaseReturn->purchase_id;
            $this->purchaseReturnRepository->delete($purchaseReturn->id);

            if ($purchaseId) {
                $purchase = Purchase::whereKey($purchaseId)->lockForUpdate()->first();
                if ($purchase) {
                    $hasOtherReturns = PurchaseReturn::where('purchase_id', $purchase->id)->exists();
                    $purchase->update(['is_return' => $hasOtherReturns ? 1 : 0]);
                }
            }

            DB::commit();

            return $this->sendSuccess('Purchase Return Deleted successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function purchaseReturnInfo(PurchaseReturn $purchaseReturn): JsonResponse
    {
        $purchaseReturn = $purchaseReturn->load(['purchaseReturnItems.product', 'warehouse', 'supplier']);
        $keyName = [
            'store_email',
            'store_name',
            'store_phone',
            'store_address',
        ];
        $companyInfo = Setting::whereIn('key', $keyName)->pluck('value', 'key')->toArray();
        if (getActiveStoreName()) {
            $companyInfo['store_name'] = getActiveStoreName();
        }
        $purchaseReturn->company_info = $companyInfo;

        return $this->sendResponse($purchaseReturn, 'Purchase Return information retrieved successfully');
    }

    /**
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig
     */
    public function pdfDownload(PurchaseReturn $purchaseReturn): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $purchaseReturn = $purchaseReturn->load('purchaseReturnItems.product', 'supplier');

        $data = [];
        if (Storage::exists('pdf/purchase_return-' . $purchaseReturn->reference_code . '.pdf')) {
            Storage::delete('pdf/purchase_return-' . $purchaseReturn->reference_code . '.pdf');
        }

        $companyLogo = getStoreLogo();
        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.purchase-return-pdf' : 'pdf.purchase-return-pdf';
        
        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('purchaseReturn', 'companyLogo'));

        $data['purchase_return_pdf_url'] = \App\Services\ProtectedExportService::storePdf('purchase_return-' . $purchaseReturn->reference_code, $pdfContent);

        return $this->sendResponse($data, 'purchase return pdf retrieved Successfully');
    }

    public function getPurchaseReturnProductReport(Request $request): PurchaseReturnCollection
    {
        $perPage = getPageSize($request);
        $productId = $request->get('product_id');
        $purchaseReturn = $this->purchaseReturnRepository->whereHas(
            'purchaseReturnItems',
            function ($q) use ($productId) {
                $q->where('product_id', '=', $productId);
            }
        )->with(['purchaseReturnItems.product', 'supplier']);

        $purchaseReturn = $purchaseReturn->paginate($perPage);
        PurchaseReturnResource::usingWithCollection();

        return new PurchaseReturnCollection($purchaseReturn);
    }
}
