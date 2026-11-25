<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Http\Resources\SupplierCollection;
use App\Http\Resources\SupplierResource;
use App\Imports\SupplierImport;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Repositories\SupplierRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Facades\Image;
use Maatwebsite\Excel\Facades\Excel;
use Prettus\Validator\Exceptions\ValidatorException;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Class SupplierAPIController
 */
class SupplierAPIController extends AppBaseController
{
    /** @var SupplierRepository */
    private $supplierRepository;

    public function __construct(SupplierRepository $supplierRepository)
    {
        $this->supplierRepository = $supplierRepository;
    }

    public function index(Request $request): SupplierCollection
    {
        $perPage = getPageSize($request);
        $suppliers = $this->supplierRepository->paginate($perPage);
        SupplierResource::usingWithCollection();

        return new SupplierCollection($suppliers);
    }

    /**
     * @throws ValidatorException
     */
    public function store(CreateSupplierRequest $request): SupplierResource
    {
        $input = $request->all();
        $supplier = $this->supplierRepository->create($input);

        return new SupplierResource($supplier);
    }

    public function show($id): SupplierResource
    {
        $supplier = $this->supplierRepository->find($id);

        return new SupplierResource($supplier);
    }

    /**
     * @throws ValidatorException
     */
    public function update(UpdateSupplierRequest $request, $id): SupplierResource
    {
        $input = $request->all();
        $supplier = $this->supplierRepository->update($input, $id);

        return new SupplierResource($supplier);
    }

    public function destroy($id): JsonResponse
    {
        $supplier = Supplier::whereKey($id)->firstOrFail();

        $linkedModels = [
            Purchase::class,
            PurchaseReturn::class,
        ];
        $useSupplier = canDelete($linkedModels, 'supplier_id', $supplier->id);
        if ($useSupplier) {
            return $this->sendError(__('messages.error.supplier_in_use'));
        }
        $this->supplierRepository->delete($supplier->id);

        return $this->sendSuccess('Supplier deleted successfully');
    }

    public function importSuppliers(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($request) {
            Excel::import(new SupplierImport(), $request->file('file'));
        });

        return $this->sendSuccess(__('messages.success.suppliers_imported'));
    }

    public function pdfDownload(Supplier $supplier): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $supplier = $supplier->load('purchases');

        $purchasesData = [];

        $purchasesData['totalPurchase'] = $supplier->purchases->count();

        $purchasesData['totalAmount'] = $supplier->purchases->sum('grand_total');

        $data = [];

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.suppliers-report-pdf' : 'pdf.suppliers-report-pdf';
        $pdfContent = generatePDF($pdfViewPath, compact('supplier', 'companyLogo', 'purchasesData'));
        $data['suppliers_report_pdf_url'] = \App\Services\ProtectedExportService::storePdf('suppliers-report-' . $supplier->id, $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }
}
