<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\CreateCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerCollection;
use App\Http\Resources\CustomerResource;
use App\Imports\CustomerImport;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SalesPayment;
use App\Repositories\CustomerRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Intervention\Image\Facades\Image;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Prettus\Validator\Exceptions\ValidatorException;

/**
 * Class CustomerAPIController
 */
class CustomerAPIController extends AppBaseController
{
    /** @var CustomerRepository */
    private $customerRepository;

    public function __construct(CustomerRepository $customerRepository)
    {
        $this->customerRepository = $customerRepository;
    }

    public function index(Request $request): CustomerCollection
    {
        $perPage = getPageSize($request);
        $customers = $this->customerRepository->paginate($perPage);
        CustomerResource::usingWithCollection();

        return new CustomerCollection($customers);
    }

    /**
     * @throws ValidatorException
     */
    public function store(CreateCustomerRequest $request): CustomerResource
    {
        $input = $request->all();
        if (! empty($input['dob'])) {
            $input['dob'] = $input['dob'] ?? date('Y/m/d');
        }
        $customer = $this->customerRepository->create($input);

        return new CustomerResource($customer);
    }

    public function show($id): CustomerResource
    {
        $customer = $this->customerRepository->find($id);

        return new CustomerResource($customer);
    }

    /**
     * @throws ValidatorException
     */
    public function update(UpdateCustomerRequest $request, $id): CustomerResource
    {
        $input = $request->all();
        if (! empty($input['dob'])) {
            $input['dob'] = $input['dob'] ?? date('Y/m/d');
        }
        $customer = $this->customerRepository->update($input, $id);

        return new CustomerResource($customer);
    }

    public function destroy($id): JsonResponse
    {
        if (getSettingValue('default_customer') == $id) {
            return $this->SendError(__('messages.error.default_customer_cant_delete'));
        }
        $this->customerRepository->delete($id);

        return $this->sendSuccess('Customer deleted successfully');
    }

    public function bestCustomersPdfDownload(): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $month = Carbon::now()->month;
        $tenantId = currentTenantId();

        $topCustomers = Customer::withoutGlobalScope('tenant')
            ->leftJoin('sales', 'customers.id', '=', 'sales.customer_id')
            ->whereMonth('sales.date', $month)
            ->where('customers.tenant_id', $tenantId)
            ->select('customers.*', DB::raw('sum(sales.grand_total) as grand_total'))
            ->groupBy('customers.id')
            ->orderBy('grand_total', 'desc')
            ->latest()
            ->take(5)
            ->withCount([
                'sales' => function ($query) use ($tenantId) {
                    $query->where('sales.tenant_id', $tenantId);
                }
            ])
            ->get();

        $data = [];

        if (Storage::exists('pdf/best-customers.pdf')) {
            Storage::delete('pdf/best-customers.pdf');
        }

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.best-customers-pdf' : 'pdf.best-customers-pdf';
        
        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('topCustomers', 'companyLogo'));

        $data['best_customers_pdf_url'] = \App\Services\ProtectedExportService::storePdf('best-customers', $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function pdfDownload(Customer $customer): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $customer = $customer->load('sales.payments');

        $salesData = [];

        $salesData['totalSale'] = $customer->sales->count();

        $salesData['totalAmount'] = $customer->sales->sum('grand_total');

        $salesData['totalPaid'] = 0;

        foreach ($customer->sales as $sale) {
            $salesData['totalPaid'] = $salesData['totalPaid'] + $sale->payments->sum('amount');
        }

        $salesData['totalSalesDue'] = $salesData['totalAmount'] - $salesData['totalPaid'];

        $data = [];

        if (Storage::exists('pdf/customers-report-' . $customer->id . '.pdf')) {
            Storage::delete('pdf/customers-report-' . $customer->id . '.pdf');
        }

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.customers-report-pdf' : 'pdf.customers-report-pdf';
        
        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('customer', 'companyLogo', 'salesData'));

        $data['customers_report_pdf_url'] = \App\Services\ProtectedExportService::storePdf('customers-report-' . $customer->id, $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function customerSalesPdfDownload(Customer $customer): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $customer = $customer->load('sales.payments');

        $data = [];

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.customer-sales-pdf' : 'pdf.customer-sales-pdf';

        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('customer', 'companyLogo'));

        $data['customers_sales_pdf_url'] = \App\Services\ProtectedExportService::storePdf('customer-sales-' . $customer->id, $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function customerQuotationsPdfDownload(Customer $customer): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $customer = $customer->load('quotations');

        $data = [];

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.customer-quotations-pdf' : 'pdf.customer-quotations-pdf';

        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('customer', 'companyLogo'));

        $data['customers_quotations_pdf_url'] = \App\Services\ProtectedExportService::storePdf('customer-quotations-' . $customer->id, $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function customerReturnsPdfDownload(Customer $customer): JsonResponse
    {
        ini_set('memory_limit', '-1');
        $customer = $customer->load('salesReturns');

        $data = [];

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.customer-returns-pdf' : 'pdf.customer-returns-pdf';

        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('customer', 'companyLogo'));

        $data['customers_returns_pdf_url'] = \App\Services\ProtectedExportService::storePdf('customer-returns-' . $customer->id, $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function customerPaymentsPdfDownload($id): JsonResponse
    {
        ini_set('memory_limit', '-1');
        // Enforce tenant-scoped customer ownership before exporting payments
        $customer = Customer::findOrFail($id);
        $saleIds = Sale::whereCustomerId($customer->id)->pluck('id')->all();

        $payments = SalesPayment::whereIn('sale_id', $saleIds)->with('sale')->get();

        $data = [];

        $companyLogo = getStoreLogo();

        $companyLogo = (string) Image::make($companyLogo)->encode('data-url');

        $pdfViewPath = getLoginUserLanguage() == 'ar' ? 'pdf.ar.customer-payments-pdf' : 'pdf.customer-payments-pdf';

        // Use helper function for PDF generation with Arabic support
        $pdfContent = generatePDF($pdfViewPath, compact('payments', 'companyLogo'));

        $data['customers_payments_pdf_url'] = \App\Services\ProtectedExportService::storePdf('customer-payments-' . $customer->id, $pdfContent);

        return $this->sendResponse($data, 'pdf retrieved Successfully');
    }

    public function importCustomers(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:5120',
        ]);

        Excel::import(new CustomerImport(), $request->file('file'));

        return $this->sendSuccess(__('messages.success.customers_imported'));
    }
}
