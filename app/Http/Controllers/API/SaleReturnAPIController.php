<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateSaleReturnRequest;
use App\Http\Requests\UpdateSaleReturnRequest;
use App\Http\Resources\SaleReturnCollection;
use App\Http\Resources\SaleReturnResource;
use App\Models\Customer;
use App\Models\ManageStock;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Setting;
use App\Models\Taxes;
use App\Models\Warehouse;
use App\Repositories\SaleReturnRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class SaleReturnAPIController extends AppBaseController
{
    /**
     * @var SaleReturnRepository
     */
    private $saleReturnRepository;

    /**
     * SaleReturnAPIController constructor.
     */
    public function __construct(SaleReturnRepository $saleReturnRepository)
    {
        $this->saleReturnRepository = $saleReturnRepository;
    }

    public function index(Request $request): SaleReturnCollection
    {
        $perPage = getPageSize($request);
        $search = $request->filter['search'] ?? '';
        $customer = (Customer::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $warehouse = (Warehouse::where('name', 'LIKE', "%$search%")->get()->count() != 0);
        $salesReturn = $this->saleReturnRepository;
        if ($customer || $warehouse) {
            $salesReturn->whereHas('customer', function (Builder $q) use ($search, $customer) {
                if ($customer) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            })->whereHas('warehouse', function (Builder $q) use ($search, $warehouse) {
                if ($warehouse) {
                    $q->where('name', 'LIKE', "%$search%");
                }
            });
        }

        if ($request->get('start_date') && $request->get('end_date')) {
            $salesReturn->whereBetween('date', [$request->get('start_date'), $request->get('end_date')]);
        }

        if ($request->get('warehouse_id')) {
            $salesReturn->where('warehouse_id', $request->get('warehouse_id'));
        }

        if ($request->get('customer_id')) {
            $salesReturn->where('customer_id', $request->get('customer_id'));
        }

        if ($request->get('status') && $request->get('status') != 'null') {
            $salesReturn->Where('status', $request->get('status'));
        }

        if ($request->get('payment_status') && $request->get('payment_status') != 'null') {
            $salesReturn->where('payment_status', $request->get('payment_status'));
        }

        $salesReturn = $salesReturn->paginate($perPage);

        SaleReturnResource::usingWithCollection();

        return new SaleReturnCollection($salesReturn);
    }

    public function store(CreateSaleReturnRequest $request): SaleReturnResource
    {
        $input = $request->all();
        $saleReturn = $this->saleReturnRepository->storeSaleReturn($input);

        return new SaleReturnResource($saleReturn);
    }

    public function show($id): SaleReturnResource
    {
        $saleReturn = $this->saleReturnRepository->find($id);

        return new SaleReturnResource($saleReturn);
    }

    public function edit(SaleReturn $salesReturn): SaleReturnResource
    {
        $salesReturn = $salesReturn->load('saleReturnItems.product', 'warehouse');

        return new SaleReturnResource($salesReturn);
    }

    public function editBySale($saleId)
    {
        $salesReturn = SaleReturn::where('sale_id', $saleId)->first();
        if (empty($salesReturn)) {
            return $this->sendError('Sale Return is not created');
        }
        $salesReturn = $salesReturn->load('saleReturnItems', 'saleReturnItems.product', 'warehouse');

        return new SaleReturnResource($salesReturn);
    }

    public function update(UpdateSaleReturnRequest $request, $id): SaleReturnResource
    {
        $input = $request->all();
        $saleReturn = $this->saleReturnRepository->updateSaleReturn($input, $id);

        return new SaleReturnResource($saleReturn);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            DB::beginTransaction();
            // S1 lock order: Parent Sale -> SaleReturn -> ManageStock
            $saleId = SaleReturn::whereKey($id)->value('sale_id');
            $sale = null;
            if ($saleId) {
                $sale = Sale::whereId($saleId)->lockForUpdate()->first();
            }

            $saleReturn = SaleReturn::with('saleReturnItems')->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($saleReturn->sale_id && (! $sale || (int) $sale->id !== (int) $saleReturn->sale_id)) {
                $sale = Sale::whereId($saleReturn->sale_id)->lockForUpdate()->first();
            }

            if ((int) $saleReturn->status === SaleReturn::RECEIVED) {
                /** @var \App\Services\StockMovementService $stockService */
                $stockService = app(\App\Services\StockMovementService::class);
                $pairs = [];
                foreach ($saleReturn->saleReturnItems as $item) {
                    $pairs[] = ['warehouse_id' => (int) $saleReturn->warehouse_id, 'product_id' => (int) $item->product_id];
                }
                $stockService->lockStockRows($pairs);

                foreach ($saleReturn->saleReturnItems as $saleReturnItem) {
                    $stockService->reverse(
                        (int) $saleReturn->warehouse_id,
                        (int) $saleReturnItem->product_id,
                        -(float) $saleReturnItem->quantity,
                        'sale_return_delete_reversal',
                        SaleReturn::class,
                        (int) $saleReturn->id,
                        null,
                        'Sale return deletion reversal (' . ($saleReturn->reference_code ?? ('#' . $saleReturn->id)) . ')',
                        'Cannot delete sale return: insufficient stock available to reverse returned quantity.'
                    );
                }
            }

            $parentSaleId = $saleReturn->sale_id;
            $this->saleReturnRepository->delete($saleReturn->id);

            if ($parentSaleId && $sale) {
                $hasOtherReturns = SaleReturn::where('sale_id', $sale->id)->exists();
                $sale->update(['is_return' => $hasOtherReturns ? 1 : 0]);
            }

            DB::commit();

            return $this->sendSuccess('Sale Return Deleted successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }

    public function saleReturnInfo(SaleReturn $salesReturn): JsonResponse
    {
        $salesReturn = $salesReturn->load('saleReturnItems.product', 'warehouse', 'customer');
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
        $salesReturn->company_info = $companyInfo;

        return $this->sendResponse($salesReturn, 'Sale Return information retrieved successfully');
    }

    /**
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig
     */
    public function pdfDownload(SaleReturn $saleReturn): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $saleReturn = $saleReturn->load('customer', 'saleReturnItems.product');
        $data = [];
        if (Storage::exists('pdf/sale_return-' . $saleReturn->reference_code . '.pdf')) {
            Storage::delete('pdf/sale_return-' . $saleReturn->reference_code . '.pdf');
        }
        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $taxes = Taxes::where('status', 1)->get();

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.sale-return-pdf' : 'pdf.sale-return-pdf';
        
        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('saleReturn', 'companyLogo', 'taxes'));

        $data['sale_return_pdf_url'] = \App\Services\ProtectedExportService::storePdf('sale_return-' . $saleReturn->reference_code, $pdfContent);

        return $this->sendResponse($data, 'Sale return pdf retrieved Successfully');
    }

    public function getSaleReturnProductReport(Request $request): SaleReturnCollection
    {
        $perPage = getPageSize($request);
        $productId = $request->get('product_id');
        $saleReturns = $this->saleReturnRepository->whereHas('saleReturnItems', function ($q) use ($productId) {
            $q->where('product_id', '=', $productId);
        })->with(['saleReturnItems.product', 'customer']);

        $saleReturns = $saleReturns->paginate($perPage);

        SaleReturnResource::usingWithCollection();

        return new SaleReturnCollection($saleReturns);
    }

    public function markAsPaid(int $id): JsonResponse
    {
        try {
            DB::beginTransaction();
            $saleReturn = SaleReturn::whereKey($id)->lockForUpdate()->firstOrFail();
            $saleReturn->update([
                'payment_status' => SaleReturn::PAID,
                'paid_amount' => (float) ($saleReturn->grand_total ?? 0),
            ]);

            DB::commit();

            return $this->sendSuccess('Mark as paid successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException|\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            DB::rollBack();
            throw $e;
        } catch (Exception $e) {
            DB::rollBack();
            throw new UnprocessableEntityHttpException($e->getMessage());
        }
    }
}
