<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\StoreAPIController;
use App\Http\Controllers\API\HoldAPIController;
use App\Http\Controllers\API\RoleAPIController;
use App\Http\Controllers\API\SaleAPIController;
use App\Http\Controllers\API\SecureFileController;
use App\Http\Controllers\API\UnitAPIController;
use App\Http\Controllers\API\UserAPIController;
use App\Http\Controllers\API\BrandAPIController;
use App\Http\Controllers\API\TaxesAPIController;
use App\Http\Controllers\API\ReportAPIController;
use App\Http\Controllers\PaypalPaymentController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\StripePaymentController;
use App\Http\Controllers\API\ExpenseAPIController;
use App\Http\Controllers\API\PaymentMethodAPIController;
use App\Http\Controllers\API\PermissionController;
use App\Http\Controllers\API\ProductAPIController;
use App\Http\Controllers\API\SettingAPIController;
use App\Http\Controllers\API\BaseUnitAPIController;
use App\Http\Controllers\API\CustomerAPIController;
use App\Http\Controllers\API\PurchaseAPIController;
use App\Http\Controllers\API\SupplierAPIController;
use App\Http\Controllers\API\TransferAPIController;
use App\Http\Controllers\MailTemplateAPIController;
use App\Http\Controllers\PaystackPaymentController;
use App\Http\Controllers\RazorpayPaymentController;
use App\Http\Controllers\API\DashboardAPIController;
use App\Http\Controllers\API\QuotationAPIController;
use App\Http\Controllers\API\VariationAPIController;
use App\Http\Controllers\API\WarehouseAPIController;
use App\Http\Controllers\API\AdjustmentAPIController;
use App\Http\Controllers\API\CouponCodeAPIController;
use App\Http\Controllers\API\SaleReturnAPIController;
use App\Http\Controllers\API\SmsSettingAPIController;
use App\Http\Controllers\API\MainProductAPIController;
use App\Http\Controllers\API\ManageStockAPIController;
use App\Http\Controllers\API\POSRegisterAPIController;
use App\Http\Controllers\API\SmsTemplateAPIController;
use App\Http\Controllers\Sadmin\CurrencyAPIController;
use App\Http\Controllers\Sadmin\LanguageAPIController;
use App\Http\Controllers\API\SalesPaymentAPIController;
use App\Http\Controllers\API\SubscriptionAPIController;
use App\Http\Controllers\API\PurchaseReturnAPIController;
use App\Http\Controllers\API\ExpenseCategoryAPIController;
use App\Http\Controllers\API\ProductCategoryAPIController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

//Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//    return $request->user();
//});

Route::get('languages', [LanguageAPIController::class, 'index']);

Route::middleware(['auth:sanctum', 'verified', 'active_user'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('subscriptions', [SubscriptionAPIController::class, 'index']);
    Route::get('plans', [SubscriptionAPIController::class, 'getPlans']);
    Route::get('current-plan', [SubscriptionAPIController::class, 'getCurrentPlan']);
    Route::get('compare/{plan}', [SubscriptionAPIController::class, 'getComparePlans']);
    Route::get('subscription/payment-methods', [SubscriptionAPIController::class, 'getPaymentMethods']);
    Route::post('subscription', [SubscriptionAPIController::class, 'createSubscription']);

    Route::post('stripe/generate-session', [StripePaymentController::class, 'generateSession'])->name('stripe-generate-session');
    Route::post('paypal/generate-session', [PaypalPaymentController::class, 'generateSession'])->name('paypal-generate-session');
    Route::post('razorpay/generate-session', [RazorpayPaymentController::class, 'generateSession'])->name('razorpay-generate-session');
    Route::post('paystack/generate-session', [PaystackPaymentController::class, 'generateSession'])->name('paystack-generate-session');

    Route::middleware(['check_subscription'])->group(function () {
        Route::resource('stores', StoreAPIController::class);
        Route::post('change-store/{store}', [StoreAPIController::class, 'changeStore']);
        Route::post('change-status/{store}', [StoreAPIController::class, 'changeStatus']);

        Route::middleware(['permission:manage_brands', 'plan_feature:inventory_management'])->group(function () {
            Route::post('/brands', [BrandAPIController::class, 'store']);
            Route::get('/brands/{id}', [BrandAPIController::class, 'show'])->name('brands.show');
            Route::post('/brands/{id}', [BrandAPIController::class, 'update']);
            Route::delete('/brands/{brand}', [BrandAPIController::class, 'destroy']);
        });
        Route::get('/brands', [BrandAPIController::class, 'index']);

        //Dashboard
        Route::middleware('permission:manage_dashboard')->group(function () {
            Route::get('today-sales-purchases-count', [DashboardAPIController::class, 'getPurchaseSalesCounts']);
            Route::get('all-sales-purchases-count', [DashboardAPIController::class, 'getAllPurchaseSalesCounts']);
            Route::get('recent-sales', [DashboardAPIController::class, 'getRecentSales']);
            Route::get('top-selling-products', [DashboardAPIController::class, 'getTopSellingProducts']);
            Route::get('week-selling-purchases', [DashboardAPIController::class, 'getWeekSalePurchases']);
            Route::get('yearly-top-selling', [DashboardAPIController::class, 'getYearlyTopSelling']);
            Route::get('top-customers', [DashboardAPIController::class, 'getTopCustomer']);
            Route::get('stock-alerts', [DashboardAPIController::class, 'stockAlerts']);
        });
        // get all permission
        Route::get('/permissions', [PermissionController::class, 'getPermissions'])->name('get-permissions');

        // roles route (fine-grained backend permission & plan_feature enforcement)
        Route::middleware(['permission:create_roles|manage_roles', 'plan_feature:roles_permission'])->group(function () {
            Route::post('roles', [RoleAPIController::class, 'store']);
        });
        Route::middleware(['permission:view_roles|manage_roles', 'plan_feature:roles_permission'])->group(function () {
            Route::get('roles/{role}', [RoleAPIController::class, 'show'])->name('roles.show');
        });
        Route::middleware(['permission:edit_roles|manage_roles', 'plan_feature:roles_permission'])->group(function () {
            Route::put('roles/{role}', [RoleAPIController::class, 'update']);
            Route::patch('roles/{role}', [RoleAPIController::class, 'update']);
        });
        Route::middleware(['permission:delete_roles|manage_roles', 'plan_feature:roles_permission'])->group(function () {
            Route::delete('roles/{role}', [RoleAPIController::class, 'destroy']);
        });
        Route::get('roles', [RoleAPIController::class, 'index']);

        // product category route (B2-01: plan_feature:inventory_management)
        Route::middleware(['permission:manage_product_categories', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('product-categories', ProductCategoryAPIController::class);
            Route::post(
                'product-categories/{product_category}',
                [ProductCategoryAPIController::class, 'update']
            )->name('product-category');
        });
        Route::get('product-categories', [ProductCategoryAPIController::class, 'index']);
        Route::middleware('permission:manage_products|manage_pos_screen')->group(function () {
            Route::post('products/generate-barcode', [ProductAPIController::class, 'generateStandaloneBarcode']);
            Route::post('products/{product}/ensure-barcode', [ProductAPIController::class, 'ensureBarcode']);
        });
        Route::get('currencies', [CurrencyAPIController::class, 'index']);

        // warehouses route (B2-01: plan_feature:inventory_management)
        Route::middleware(['permission:view_warehouses|manage_warehouses|manage_reports', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('warehouses', WarehouseAPIController::class)->only(['index', 'show']);
            Route::get('warehouse-details/{id}', [WarehouseAPIController::class, 'warehouseDetails']);
        });
        Route::middleware(['permission:create_warehouses|manage_warehouses', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('warehouses', WarehouseAPIController::class)->only(['store']);
        });
        Route::middleware(['permission:edit_warehouses|manage_warehouses', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('warehouses', WarehouseAPIController::class)->only(['edit', 'update']);
        });
        Route::middleware(['permission:delete_warehouses|manage_warehouses', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('warehouses', WarehouseAPIController::class)->only(['destroy']);
        });
        Route::get('warehouses', [WarehouseAPIController::class, 'index']);

        // units route (B2-01: plan_feature:inventory_management, B5-06 & B5-07)
        Route::middleware(['permission:view_units|manage_units|manage_products|manage_purchase|manage_sale', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('units', UnitAPIController::class)->only(['index', 'show']);
            Route::resource('base-units', BaseUnitAPIController::class)->only(['index', 'show']);
        });
        Route::middleware(['permission:create_units|manage_units', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('units', UnitAPIController::class)->only(['store']);
            Route::resource('base-units', BaseUnitAPIController::class)->only(['store']);
        });
        Route::middleware(['permission:edit_units|manage_units', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('units', UnitAPIController::class)->only(['edit', 'update']);
            Route::resource('base-units', BaseUnitAPIController::class)->only(['edit', 'update']);
        });
        Route::middleware(['permission:delete_units|manage_units', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('units', UnitAPIController::class)->only(['destroy']);
            Route::resource('base-units', BaseUnitAPIController::class)->only(['destroy']);
        });
        Route::get('units', [UnitAPIController::class, 'index']);

        // products route (B2-01: plan_feature:inventory_management on inventory management mutations, B5-06)
        Route::middleware(['permission:create_products|manage_products', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('products', ProductAPIController::class)->only(['store']);
            Route::resource('main-products', MainProductAPIController::class)->only(['store']);
            Route::post('import-products', [ProductAPIController::class, 'importProducts']);
        });
        Route::middleware(['permission:edit_products|manage_products', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('products', ProductAPIController::class)->only(['edit', 'update']);
            Route::resource('main-products', MainProductAPIController::class)->only(['edit', 'update']);
            Route::post('products/{product}', [ProductAPIController::class, 'update']);
            Route::post('main-products/{product}', [MainProductAPIController::class, 'update']);
            Route::delete('products-image-delete/{mediaId}', [ProductAPIController::class, 'productImageDelete'])->name('products-image-delete');
        });
        Route::middleware(['permission:delete_products|manage_products', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('products', ProductAPIController::class)->only(['destroy']);
            Route::resource('main-products', MainProductAPIController::class)->only(['destroy']);
        });
        Route::middleware(['permission:view_products|manage_products|manage_reports', 'plan_feature:inventory_management'])->group(function () {
            Route::get('products-export-excel/{id?}', [ProductAPIController::class, 'getProductExportExcel'])->name('products-export-excel');
        });
        Route::middleware('permission:view_products|manage_products|manage_pos_screen|manage_sale|manage_purchase|manage_quotations|manage_transfers|manage_adjustments|manage_reports')->group(function () {
            Route::get('products/by-barcode', [ProductAPIController::class, 'findByBarcode']);
            Route::get('products', [ProductAPIController::class, 'index']);
            Route::get('products/{product}', [ProductAPIController::class, 'show'])->name('products.show');
            Route::get('main-products', [MainProductAPIController::class, 'index']);
            Route::get('main-products/{main_product}', [MainProductAPIController::class, 'show'])->name('main-products.show');
            Route::get('get-all-products', [ProductAPIController::class, 'getAllProducts']);
        });

        Route::middleware(['permission:manage_variations', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('variations', VariationAPIController::class)->except(['index']);
        });
        Route::get('variations', [VariationAPIController::class, 'index']);

        Route::middleware(['permission:view_transfers|manage_transfers', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('transfers', TransferAPIController::class)->only(['index', 'show']);
        });
        Route::middleware(['permission:create_transfers|manage_transfers', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('transfers', TransferAPIController::class)->only(['store']);
        });
        Route::middleware(['permission:edit_transfers|manage_transfers', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('transfers', TransferAPIController::class)->only(['edit', 'update']);
        });
        Route::middleware(['permission:delete_transfers|manage_transfers', 'plan_feature:inventory_management'])->group(function () {
            Route::resource('transfers', TransferAPIController::class)->only(['destroy']);
        });

        Route::middleware('permission:manage_customers')->group(function () {
            Route::post('import-customers', [CustomerAPIController::class, 'importCustomers']);
        });

        // customers route
        Route::middleware('permission:manage_customers')->group(function () {
            Route::resource('customers', CustomerAPIController::class);
        });
        Route::get('customers', [CustomerAPIController::class, 'index']);


        // Users routes (fine-grained permissions; manage_reports is read-only)
        Route::middleware('permission:view_users|manage_users|manage_reports')->group(function () {
            Route::get('users', [UserAPIController::class, 'index']);
            Route::get('users/{user}', [UserAPIController::class, 'show'])->name('users.show');
        });
        Route::middleware('permission:create_users|manage_users')->group(function () {
            Route::post('users', [UserAPIController::class, 'store']);
        });
        Route::middleware('permission:edit_users|manage_users')->group(function () {
            Route::put('users/{user}', [UserAPIController::class, 'update']);
            Route::patch('users/{user}', [UserAPIController::class, 'update']);
            Route::post('users/{user}', [UserAPIController::class, 'update']);
            Route::post('users/status/{user_id}', [UserAPIController::class, 'statusUpdate']);
            Route::post('/change-user-password', [UserAPIController::class, 'changeUserPassword']);
        });
        Route::middleware('permission:delete_users|manage_users')->group(function () {
            Route::delete('users/{user}', [UserAPIController::class, 'destroy']);
        });
        // update user profile
        Route::get('edit-profile', [UserAPIController::class, 'editProfile'])->name('edit-profile');
        Route::post('update-profile', [UserAPIController::class, 'updateProfile'])->name('update-profile');
        Route::patch('/change-password', [UserAPIController::class, 'changePassword'])->name('user.changePassword');

        //suppliers route (B5-06)
        Route::middleware('permission:view_suppliers|manage_suppliers|manage_purchase|manage_purchase_return|manage_reports')->group(function () {
            Route::resource('suppliers', SupplierAPIController::class)->only(['index', 'show']);
        });
        Route::middleware('permission:create_suppliers|manage_suppliers')->group(function () {
            Route::resource('suppliers', SupplierAPIController::class)->only(['store']);
            Route::post('import-suppliers', [SupplierAPIController::class, 'importSuppliers']);
        });
        Route::middleware('permission:edit_suppliers|manage_suppliers')->group(function () {
            Route::resource('suppliers', SupplierAPIController::class)->only(['edit', 'update']);
        });
        Route::middleware('permission:delete_suppliers|manage_suppliers')->group(function () {
            Route::resource('suppliers', SupplierAPIController::class)->only(['destroy']);
        });
        Route::get('suppliers', [SupplierAPIController::class, 'index']);

        //sale (C1.2: manage_reports is strictly read-only, B5-06)
        Route::middleware('permission:create_sale|manage_sale|manage_pos_screen')->group(function () {
            Route::resource('sales', SaleAPIController::class)->only(['store']);
            Route::post('sales/{sale}/capture-payment', [SalesPaymentAPIController::class, 'createSalePayment']);
        });
        Route::middleware('permission:edit_sale|manage_sale|manage_pos_screen')->group(function () {
            Route::resource('sales', SaleAPIController::class)->only(['edit', 'update']);
            Route::post('sales/{salesPayment}/payment', [SalesPaymentAPIController::class, 'updateSalePayment']);
            Route::delete('sales/{id}/payment', [SalesPaymentAPIController::class, 'deletePayment']);
        });
        Route::middleware('permission:delete_sale|manage_sale')->group(function () {
            Route::resource('sales', SaleAPIController::class)->only(['destroy']);
        });
        Route::middleware('permission:view_sale|manage_sale|manage_pos_screen|manage_reports')->group(function () {
            Route::get('sales', [SaleAPIController::class, 'index']);
            Route::get('sales/{sale}', [SaleAPIController::class, 'show'])->name('sales.show');
            Route::get('sale-pdf-download/{sale}', [SaleAPIController::class, 'pdfDownload'])->name('sale-pdf-download');
            Route::get('sale-info/{sale}', [SaleAPIController::class, 'saleInfo'])->name('sale-info');
            Route::get('sales/{sale}/payments', [SalesPaymentAPIController::class, 'getAllPayments']);
        });

        Route::middleware(['permission:manage_pos_screen|manage_sale', 'plan_feature:pos_management'])->group(function () {
            Route::resource('holds', HoldAPIController::class);
        });

        // Quotation (C1.2: manage_reports is strictly read-only)
        Route::middleware('permission:manage_quotations')->group(function () {
            Route::resource('quotations', QuotationAPIController::class)->except(['index', 'show']);
        });
        Route::middleware('permission:manage_quotations|manage_reports')->group(function () {
            Route::get('quotations', [QuotationAPIController::class, 'index']);
            Route::get('quotations/{quotation}', [QuotationAPIController::class, 'show'])->name('quotations.show');
            Route::get('quotation-info/{quotation}', [QuotationAPIController::class, 'quotationInfo']);
            Route::get('quotation-pdf-download/{quotation}', [QuotationAPIController::class, 'pdfDownload']);
        });

        Route::middleware(['permission:manage_email_templates', 'plan_feature:emails_support'])->group(function () {
            Route::resource('mail-templates', MailTemplateAPIController::class);
            Route::post('mail-template-status/{id}', [MailTemplateAPIController::class, 'changeActiveStatus']);
        });

        Route::middleware(['permission:manage_sms_templates', 'plan_feature:sms_support'])->group(function () {
            Route::resource('sms-templates', SmsTemplateAPIController::class);
            Route::post('sms-template-status/{id}', [SmsTemplateAPIController::class, 'changeActiveStatus']);
        });

        //sale return (C1.2: manage_reports is strictly read-only, B5-06)
        Route::middleware('permission:view_sale_return|manage_sale_return|manage_reports')->group(function () {
            Route::get('sales-return', [SaleReturnAPIController::class, 'index']);
            Route::get('sales-return/{sales_return}', [SaleReturnAPIController::class, 'show'])->name('sales-return.show');
            Route::get(
                'sale-return-info/{sales_return}',
                [SaleReturnAPIController::class, 'saleReturnInfo']
            )->name('sale-return-info');
            Route::get(
                'sale-return-pdf-download/{sale_return}',
                [SaleReturnAPIController::class, 'pdfDownload']
            )->name('sale-return-pdf-download');
        });
        Route::middleware('permission:create_sale_return|manage_sale_return')->group(function () {
            Route::resource('sales-return', SaleReturnAPIController::class)->only(['store']);
        });
        Route::middleware('permission:edit_sale_return|manage_sale_return')->group(function () {
            Route::resource('sales-return', SaleReturnAPIController::class)->only(['edit', 'update']);
            Route::get('sales-return/paid/{id}', [SaleReturnAPIController::class, 'markAsPaid']);
            Route::get('sales-return-edit/{id}', [SaleReturnAPIController::class, 'editBySale']);
        });
        Route::middleware('permission:delete_sale_return|manage_sale_return')->group(function () {
            Route::resource('sales-return', SaleReturnAPIController::class)->only(['destroy']);
        });

        //expense category route
        Route::middleware('permission:manage_expense_categories')->group(function () {
            Route::resource('expense-categories', ExpenseCategoryAPIController::class);
        });
        Route::get('expense-categories', [ExpenseCategoryAPIController::class, 'index']);

        //expense route
        Route::middleware('permission:manage_expenses')->group(function () {
            Route::resource('expenses', ExpenseAPIController::class)->except(['index', 'show']);
        });
        Route::middleware('permission:manage_expenses|manage_reports')->group(function () {
            Route::get('expenses', [ExpenseAPIController::class, 'index']);
            Route::get('expenses/{expense}', [ExpenseAPIController::class, 'show'])->name('expenses.show');
        });

        //setting route
        Route::get('taxes', [TaxesAPIController::class, 'index']);
        Route::middleware('permission:manage_setting')->group(function () {
            Route::resource('taxes', TaxesAPIController::class)->except(['index']);
            Route::get('taxes/status-change/{tax}', [TaxesAPIController::class, 'changeStatus']);
            Route::resource('settings', SettingAPIController::class);
            Route::post('settings', [SettingAPIController::class, 'update']);
            Route::get('states/{id}', [SettingAPIController::class, 'getStates']);
            Route::post('receipt-settings/update', [SettingAPIController::class, 'updateReceiptSetting']);
            Route::get('cache-clear', [SettingAPIController::class, 'clearCache'])->name('cache-clear');

            // B2-02: POS & Dual-Screen settings require plan_feature:pos_management
            Route::middleware('plan_feature:pos_management')->group(function () {
                Route::get('pos-settings', [SettingAPIController::class, 'getPosSettings']);
                Route::post('pos-settings/update', [SettingAPIController::class, 'updatePosSettings']);
                Route::get('dual-screen-settings', [SettingAPIController::class, 'getDualScreenSettings']);
                Route::post('dual-screen-settings/update', [SettingAPIController::class, 'updateDualScreenSettings']);
            });

            Route::resource('payment-methods', PaymentMethodAPIController::class)->except(['index']);
            Route::post('payment-methods/status-change/{payment_method}', [PaymentMethodAPIController::class, 'changeStatus']);
        });

        Route::get('payment-methods', [PaymentMethodAPIController::class, 'index']);

        Route::middleware(['permission:manage_sms_apis', 'plan_feature:sms_support'])->group(function () {
            Route::resource('sms-settings', SmsSettingAPIController::class);
            Route::post('sms-settings', [SmsSettingAPIController::class, 'update']);
        });

        Route::get('settings', [SettingAPIController::class, 'index']);

        //purchase routes (C1.2: manage_reports is strictly read-only, B5-06)
        Route::middleware('permission:view_purchase|manage_purchase|manage_reports')->group(function () {
            Route::get('purchases', [PurchaseAPIController::class, 'index']);
            Route::get('purchases/{purchase}', [PurchaseAPIController::class, 'show'])->name('purchases.show');
            Route::get(
                'purchase-pdf-download/{purchase}',
                [PurchaseAPIController::class, 'pdfDownload']
            )->name('purchase-pdf-download');
            Route::get('purchase-info/{purchase}', [PurchaseAPIController::class, 'purchaseInfo'])->name('purchase-info');
        });
        Route::middleware('permission:create_purchase|manage_purchase')->group(function () {
            Route::resource('purchases', PurchaseAPIController::class)->only(['store']);
        });
        Route::middleware('permission:edit_purchase|manage_purchase')->group(function () {
            Route::resource('purchases', PurchaseAPIController::class)->only(['edit', 'update']);
        });
        Route::middleware('permission:delete_purchase|manage_purchase')->group(function () {
            Route::resource('purchases', PurchaseAPIController::class)->only(['destroy']);
        });

        Route::middleware(['permission:view_adjustments|manage_adjustments', 'plan_feature:adjustments'])->group(function () {
            Route::resource('adjustments', AdjustmentAPIController::class)->only(['index', 'show']);
        });
        Route::middleware(['permission:create_adjustments|manage_adjustments', 'plan_feature:adjustments'])->group(function () {
            Route::resource('adjustments', AdjustmentAPIController::class)->only(['store']);
        });
        Route::middleware(['permission:edit_adjustments|manage_adjustments', 'plan_feature:adjustments'])->group(function () {
            Route::resource('adjustments', AdjustmentAPIController::class)->only(['edit', 'update']);
        });
        Route::middleware(['permission:delete_adjustments|manage_adjustments', 'plan_feature:adjustments'])->group(function () {
            Route::resource('adjustments', AdjustmentAPIController::class)->only(['destroy']);
        });

        //purchase return routes (C1.2: manage_reports is strictly read-only, B5-06)
        Route::middleware('permission:view_purchase_return|manage_purchase_return|manage_reports')->group(function () {
            Route::get('purchases-return', [PurchaseReturnAPIController::class, 'index']);
            Route::get('purchases-return/{purchases_return}', [PurchaseReturnAPIController::class, 'show'])->name('purchases-return.show');
            Route::get(
                'purchase-return-info/{purchase_return}',
                [PurchaseReturnAPIController::class, 'purchaseReturnInfo']
            )->name('purchase-return-info');
            Route::get(
                'purchase-return-pdf-download/{purchase_return}',
                [PurchaseReturnAPIController::class, 'pdfDownload']
            )->name('purchase-return-pdf-download');
        });
        Route::middleware('permission:create_purchase_return|manage_purchase_return')->group(function () {
            Route::resource('purchases-return', PurchaseReturnAPIController::class)->only(['store']);
        });
        Route::middleware('permission:edit_purchase_return|manage_purchase_return')->group(function () {
            Route::resource('purchases-return', PurchaseReturnAPIController::class)->only(['edit', 'update']);
            Route::get('purchase-return-edit/{id}', [PurchaseReturnAPIController::class, 'editByPurchase']);
        });
        Route::middleware('permission:delete_purchase_return|manage_purchase_return')->group(function () {
            Route::resource('purchases-return', PurchaseReturnAPIController::class)->only(['destroy']);
        });

        //Language Change
        Route::post('change-language', [UserAPIController::class, 'updateLanguage']);

        // Reports (protected with backward-compatible report/dashboard/POS permissions + plan_feature:reports)
        Route::middleware(['permission:manage_reports|manage_dashboard|manage_pos_screen', 'plan_feature:reports'])->group(function () {
            Route::get('warehouse-report', [WarehouseAPIController::class, 'warehouseReport'])->name('report-warehouse');
            Route::get('sales-report-excel', [ReportAPIController::class, 'getWarehouseSaleReportExcel'])->name('report-getSaleReportExcel');
            Route::get('purchases-report-excel', [ReportAPIController::class, 'getWarehousePurchaseReportExcel']);
            Route::get('sales-return-report-excel', [ReportAPIController::class, 'getWarehouseSaleReturnReportExcel'])->name('report-getSaleReturnReportExcel');
            Route::get('purchases-return-report-excel', [ReportAPIController::class, 'getWarehousePurchaseReturnReportExcel'])->name('report-getPurchaseReturnReportExcel');
            Route::get('expense-report-excel', [ReportAPIController::class, 'getWarehouseExpenseReportExcel'])->name('report-getExpenseReportExcel');
            Route::get('total-sale-report-excel', [ReportAPIController::class, 'getSalesReportExcel'])->name('report-getSalesReportExcel');
            Route::get('total-purchase-report-excel', [ReportAPIController::class, 'getPurchaseReportExcel']);
            Route::get('top-selling-product-report-excel', [ReportAPIController::class, 'getSellingProductReportExcel']);
            Route::get('top-selling-product-report', [ReportAPIController::class, 'getSellingProductReport']);
            Route::get('supplier-report', [ReportAPIController::class, 'getSupplierReport']);
            Route::get('supplier-pdf-download/{supplier}', [SupplierAPIController::class, 'pdfDownload']);
            Route::get('supplier-purchases-report/{supplier_id}', [ReportAPIController::class, 'getSupplierPurchasesReport']);
            Route::get('supplier-purchases-return-report/{supplier_id}', [ReportAPIController::class, 'getSupplierPurchasesReturnReport']);
            Route::get('supplier-report-info/{supplier_id}', [ReportAPIController::class, 'getSupplierInfo']);
            Route::get('profit-loss-report', [ReportAPIController::class, 'getProfitLossReport']);
            Route::get('best-customers-report', [ReportAPIController::class, 'getBestCustomersReport']);
            Route::get('best-customers-pdf-download', [CustomerAPIController::class, 'bestCustomersPdfDownload']);
            Route::get('customer-report', [ReportAPIController::class, 'getCustomerReport']);
            Route::get('customer-payments-report/{customer}', [ReportAPIController::class, 'getCustomerPaymentsReport']);
            Route::get('customer-info/{customer}', [ReportAPIController::class, 'getCustomerInfo']);
            Route::get('customer-pdf-download/{customer}', [CustomerAPIController::class, 'pdfDownload']);
            Route::get('customer-sales-pdf-download/{customer}', [CustomerAPIController::class, 'customerSalesPdfDownload']);
            Route::get('customer-quotations-pdf-download/{customer}', [CustomerAPIController::class, 'customerQuotationsPdfDownload']);
            Route::get('customer-returns-pdf-download/{customer}', [CustomerAPIController::class, 'customerReturnsPdfDownload']);
            Route::get('customer-payments-pdf-download/{customer}', [CustomerAPIController::class, 'customerPaymentsPdfDownload']);
            Route::get('product-stock-alerts/{warehouse_id?}', [ReportAPIController::class, 'stockAlerts']);
            Route::get('stock-report', [ManageStockAPIController::class, 'stockReport'])->name('report-stockReport');
            Route::get('stock-movements', [ManageStockAPIController::class, 'stockMovements'])->name('report-stockMovements');
            Route::get('stock-valuation', [ManageStockAPIController::class, 'stockValuation'])->name('report-stockValuation');
            Route::get('stock-report-excel', [ReportAPIController::class, 'stockReportExcel'])->name('report-stockReportExcel');
            Route::get('get-sale-product-report', [SaleAPIController::class, 'getSaleProductReport'])->name('report-get-sale-product-report');
            Route::get('get-purchase-product-report', [PurchaseAPIController::class, 'getPurchaseProductReport'])->name('report-get-purchase-product-report');
            Route::get('get-sale-return-product-report', [SaleReturnAPIController::class, 'getSaleReturnProductReport']);
            Route::get('get-purchase-return-product-report', [PurchaseReturnAPIController::class, 'getPurchaseReturnProductReport']);
            Route::get('today-sales-overall-report', [ReportAPIController::class, 'getTodaySalesOverallReport']);
            Route::get('get-product-sale-report-excel', [ReportAPIController::class, 'getProductSaleReportExport']);
            Route::get('get-product-purchase-report-excel', [ReportAPIController::class, 'getPurchaseProductReportExport']);
            Route::get('get-product-sale-return-report-excel', [ReportAPIController::class, 'getSaleReturnProductReportExport']);
            Route::get('get-product-purchase-return-report-excel', [ReportAPIController::class, 'getPurchaseReturnProductReportExport']);
            Route::get('get-product-count', [ReportAPIController::class, 'getProductQuantity']);
        });

        Route::get('config', [UserAPIController::class, 'config']);

        // POS Register routes (C1.3: plan_feature:pos_management for entry/close/details; reports for register-report)
        Route::middleware(['permission:manage_pos_screen|manage_reports', 'plan_feature:pos_management'])->group(function () {
            Route::get('get-register-details/{user?}', [POSRegisterAPIController::class, 'getRegisterDetails']);
            Route::post('register-entry', [POSRegisterAPIController::class, 'entry']);
            Route::post('register-close', [POSRegisterAPIController::class, 'closeRegister']);
        });
        Route::middleware(['permission:manage_pos_screen|manage_reports', 'plan_feature:reports'])->group(function () {
            Route::get('register-report', [POSRegisterAPIController::class, 'registerReport']);
        });

        // Coupon Code Routes
        Route::middleware('permission:manage_sale|manage_pos_screen')->group(function () {
            Route::resource('coupon-codes', CouponCodeAPIController::class);
        });
        Route::get('front-setting', [SettingAPIController::class, 'getFrontSettingsValue'])->name('front-settings');
        Route::middleware('permission:manage_reports|manage_sale|manage_purchase|manage_pos_screen|manage_setting')->group(function () {
            Route::get('private-files/{path}', [SecureFileController::class, 'download'])->where('path', '.*')->name('private-files.download');
        });
    });
});

Route::post('login', [AuthController::class, 'login'])->name('login');
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');

Route::post(
    '/forgot-password',
    [AuthController::class, 'sendPasswordResetLinkEmail']
)->middleware('throttle:5,1')->name('password.email');

// Route::get(
//     '/email/verify',
//     [AuthController::class, 'sendEmailVerificationNotification']
// )->middleware('throttle:5,1')->name('verification.verify');

Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

Route::get('front-cms', [SettingAPIController::class, 'getFrontCms']);

Route::post('validate-auth-token', [AuthController::class, 'isValidToken']);

// Signed asynchronous payment gateway webhooks (verified cryptographically per provider)
Route::prefix('webhooks')->group(function () {
    Route::post('stripe', [PaymentWebhookController::class, 'handleStripe'])->name('webhooks.stripe');
    Route::post('paypal', [PaymentWebhookController::class, 'handlePaypal'])->name('webhooks.paypal');
    Route::post('razorpay', [PaymentWebhookController::class, 'handleRazorpay'])->name('webhooks.razorpay');
    Route::post('paystack', [PaymentWebhookController::class, 'handlePaystack'])->name('webhooks.paystack');
});
