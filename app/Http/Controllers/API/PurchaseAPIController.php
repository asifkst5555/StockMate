<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreatePurchaseRequest;
use App\Http\Requests\UpdatePurchaseRequest;
use App\Http\Resources\PurchaseCollection;
use App\Http\Resources\PurchaseResource;
use App\Models\ManageStock;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Repositories\PurchaseRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Intervention\Image\Facades\Image;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Class PurchaseAPIController
 */
class PurchaseAPIController extends AppBaseController
{
    /** @var PurchaseRepository */
    private $purchaseRepository;

    public function __construct(PurchaseRepository $purchaseRepository)
    {
        $this->purchaseRepository = $purchaseRepository;
    }

    public function index(Request $request): PurchaseCollection
    {
        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $supplier = (Supplier::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $products = (Product::where('name', 'LIKE', "%$search%")->orWhere('code', 'LIKE', "%$search%")->get()->count() != 0);
        $purchases = $this->purchaseRepository;
        if ($supplier) {
            $purchases->whereHas('supplier', function (Builder $q) use ($search, $supplier) {
                if ($supplier) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }
        if ($warehouse) {
            $purchases->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }
        if ($products) {
            $purchases->whereHas('purchaseItems', function (Builder $q) use ($search) {
                $q->whereHas('product', function (Builder $q) use ($search) {
                    $q->where('name', 'LIKE', "%$search%")
                        ->orWhere('code', 'LIKE', "%$search%");
                });
            });
        }

        if ($request->get('start_date') && $request->get('end_date')) {
            $purchases->whereBetween('date', [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $purchases->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('status')) {
            $purchases->where('status', $request->get('status'));
        }

        $purchases = $purchases->paginate($perPage);

        PurchaseResource::usingWithCollection();

        return new PurchaseCollection($purchases);
    }

    public function store(CreatePurchaseRequest $request): PurchaseResource
    {
        $input = $request->all();
        $purchase = $this->purchaseRepository->storePurchase($input);

        return new PurchaseResource($purchase);
    }

    public function show($id): PurchaseResource
    {
        $purchase = $this->purchaseRepository->find($id);

        return new PurchaseResource($purchase);
    }

    public function edit(Purchase $purchase): PurchaseResource
    {
        $purchase = $purchase->load('purchaseItems.product.stocks', 'warehouse');

        return new PurchaseResource($purchase);
    }

    public function update(UpdatePurchaseRequest $request, $id): PurchaseResource
    {
        $input = $request->all();
        $purchase = $this->purchaseRepository->updatePurchase($input, $id);

        return new PurchaseResource($purchase);
    }

    public function destroy($id): JsonResponse
    {
        try {
            DB::beginTransaction();

            // S1 lock order: Eligible RECEIVED Purchases for supplier+warehouse (+ target ID) sorted ASC -> PurchaseReturns ASC -> ManageStock
            $meta = Purchase::whereKey($id)->first(['id', 'supplier_id', 'warehouse_id', 'status']);
            $lockedReceivedIds = [];
            if ($meta) {
                $lockIds = Purchase::where('supplier_id', $meta->supplier_id)
                    ->where('warehouse_id', $meta->warehouse_id)
                    ->where('status', Purchase::RECEIVED)
                    ->pluck('id')
                    ->all();
                $lockIds[] = (int) $id;
                $lockIds = array_values(array_unique(array_map('intval', $lockIds)));
                sort($lockIds);
                $lockedPurchases = Purchase::whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->get();
                $lockedReceivedIds = $lockedPurchases
                    ->filter(static fn (Purchase $p): bool => (int) $p->supplier_id === (int) $meta->supplier_id
                        && (int) $p->warehouse_id === (int) $meta->warehouse_id
                        && (int) $p->status === Purchase::RECEIVED)
                    ->pluck('id')
                    ->all();
            }

            $purchase = Purchase::with('purchaseItems')->where('id', $id)->lockForUpdate()->firstOrFail();

            // C2-B3-03: Block parent Purchase deletion if any PurchaseReturn is linked to it
            if (PurchaseReturn::where('purchase_id', $purchase->id)->lockForUpdate()->exists()) {
                throw new UnprocessableEntityHttpException(
                    'Cannot delete purchase: one or more purchase returns are linked to this purchase. Please delete the purchase returns first.'
                );
            }

            // C2-B3-3A Microfix 1: Block deletion if a RECEIVED Purchase backs existing standalone (purchase_id = null) PurchaseReturn quota
            if ((int) $purchase->status === Purchase::RECEIVED) {
                $poolReturns = PurchaseReturn::where('supplier_id', $purchase->supplier_id)
                    ->where('warehouse_id', $purchase->warehouse_id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $hasUnlinkedReturns = $poolReturns->contains(static fn (PurchaseReturn $r): bool => $r->purchase_id === null);

                if ($hasUnlinkedReturns && $purchase->purchaseItems->isNotEmpty()) {
                    $remainingReceivedIds = array_values(array_filter(
                        $lockedReceivedIds,
                        static fn (int $pid): bool => $pid !== (int) $purchase->id
                    ));
                    $poolReturnIds = $poolReturns->pluck('id')->all();
                    $affectedProductIds = $purchase->purchaseItems->pluck('product_id')->map(static fn ($pid): int => (int) $pid)->unique()->values()->all();

                    foreach ($affectedProductIds as $productId) {
                        $returnedQty = (float) PurchaseReturnItem::whereIn('purchase_return_id', $poolReturnIds)
                            ->where('product_id', $productId)
                            ->sum('quantity');
                        if ($returnedQty <= 0.0) {
                            continue;
                        }

                        $remainingQty = ! empty($remainingReceivedIds)
                            ? (float) PurchaseItem::whereIn('purchase_id', $remainingReceivedIds)->where('product_id', $productId)->sum('quantity')
                            : 0.0;

                        if ($returnedQty > ($remainingQty + 1e-9)) {
                            throw new UnprocessableEntityHttpException(
                                'Cannot delete purchase: remaining received purchases for this supplier and warehouse cannot cover existing purchase returns.'
                            );
                        }

                        $returnedSubTotal = (float) PurchaseReturnItem::whereIn('purchase_return_id', $poolReturnIds)
                            ->where('product_id', $productId)
                            ->sum('sub_total');
                        $remainingSubTotal = ! empty($remainingReceivedIds)
                            ? (float) PurchaseItem::whereIn('purchase_id', $remainingReceivedIds)->where('product_id', $productId)->sum('sub_total')
                            : 0.0;

                        if ($returnedSubTotal > ($remainingSubTotal + 0.02)) {
                            throw new UnprocessableEntityHttpException(
                                'Cannot delete purchase: remaining received purchase values for this supplier and warehouse cannot cover existing purchase return credits.'
                            );
                        }
                    }

                    $unlinkedReturnsTouchedAffectedProducts = PurchaseReturnItem::whereIn(
                        'purchase_return_id',
                        $poolReturns->whereNull('purchase_id')->pluck('id')->all()
                    )->whereIn('product_id', $affectedProductIds)->exists();

                    if ($unlinkedReturnsTouchedAffectedProducts) {
                        $remainingPoolGrandTotal = ! empty($remainingReceivedIds)
                            ? (float) Purchase::whereIn('id', $remainingReceivedIds)->sum('grand_total')
                            : 0.0;
                        $poolReturnedGrandTotal = (float) $poolReturns->sum('grand_total');
                        if ($poolReturnedGrandTotal > ($remainingPoolGrandTotal + 0.02)) {
                            throw new UnprocessableEntityHttpException(
                                'Cannot delete purchase: total existing purchase returns would exceed remaining received purchase totals.'
                            );
                        }
                    }
                }
            }

            // E5: Only reverse stock if the purchase was actually RECEIVED
            if ((int) $purchase->status === Purchase::RECEIVED) {
                /** @var \App\Services\StockMovementService $stockService */
                $stockService = app(\App\Services\StockMovementService::class);
                $pairs = [];
                foreach ($purchase->purchaseItems as $purchaseItem) {
                    $pairs[] = ['warehouse_id' => (int) $purchase->warehouse_id, 'product_id' => (int) $purchaseItem->product_id];
                }
                $stockService->lockStockRows($pairs);

                foreach ($purchase->purchaseItems as $purchaseItem) {
                    $stockService->reverse(
                        (int) $purchase->warehouse_id,
                        (int) $purchaseItem->product_id,
                        -(float) $purchaseItem->quantity,
                        'purchase_delete_reversal',
                        Purchase::class,
                        (int) $purchase->id,
                        isset($purchaseItem->product_cost) ? (float) $purchaseItem->product_cost : null,
                        'Purchase deletion reversal (' . ($purchase->reference_code ?? ('#' . $purchase->id)) . ')',
                        __('messages.error.available_quantity')
                    );
                }
            }

            $this->purchaseRepository->delete($purchase->id);
            DB::commit();

            return $this->sendSuccess('Purchase Deleted successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    /**
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig
     */
    public function pdfDownload(Purchase $purchase): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $purchase = $purchase->load('purchaseItems.product', 'supplier');

        $data = [];
        if (Storage::exists('pdf.purchase-pdf-' . $purchase->reference_code . '.pdf')) {
            Storage::delete('pdf.purchase-pdf-' . $purchase->reference_code . '.pdf');
        }

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');
        $purchasePaymentTypeName = $purchase->payment_type ? PaymentMethod::where('id', $purchase->payment_type)->first()->name : "";
        $purchase->payment_type_name = $purchasePaymentTypeName;
        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.purchase-pdf' : 'pdf.purchase-pdf';
        
        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('purchase', 'companyLogo'));

        $data['purchase_pdf_url'] = \App\Services\ProtectedExportService::storePdf('Purchase-' . $purchase->reference_code, $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function purchaseInfo(Purchase $purchase): JsonResponse
    {
        $purchase = $purchase->load(['purchaseItems.product', 'warehouse', 'supplier']);
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
        $purchase->company_info = $companyInfo;

        return $this->sendResponse($purchase, 'Purchase information retrieved successfully');
    }

    public function getPurchaseProductReport(Request $request): PurchaseCollection
    {
        $perPage = getPageSize($request);
        $productId = $request->get('product_id');
        $purchases = $this->purchaseRepository->whereHas('purchaseItems', function ($q) use ($productId) {
            $q->where('product_id', '=', $productId);
        })->with(['purchaseItems.product', 'supplier']);

        $purchases = $purchases->paginate($perPage);

        PurchaseResource::usingWithCollection();

        return new PurchaseCollection($purchases);
    }
}
