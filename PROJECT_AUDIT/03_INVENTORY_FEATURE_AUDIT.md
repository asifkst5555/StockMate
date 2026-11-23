# Phase 3: Inventory Management Feature Audit

Every feature below was verified by inspecting the actual models, controllers, repositories, migrations, routes, and React components.

## A. Product Management

| Feature | Classification | Evidence & Notes |
|---|---|---|
| Create products | Fully implemented (needs runtime test) | `MainProductAPIController`, `ProductAPIController`, `ProductRepository::storeProduct()` |
| Edit products | Fully implemented (needs runtime test) | `MainProductAPIController::update`, `ProductAPIController::update` |
| Delete products | Fully implemented (with guard) | Checks `PurchaseItem`/`SaleItem` existence via `canDelete()`; blocks deleting last variation |
| Product SKU / Code | Fully implemented | `products.code`, `main_products.code` |
| Barcode generation & printing | Fully implemented | `picqer/php-barcode-generator`, `ProductAPIController::generateStandaloneBarcode`, `printBarcode` UI |
| Product images | Fully implemented | `spatie/laravel-medialibrary` on `MainProduct`; `products-image-delete/{mediaId}` |
| Product categories | Fully implemented | `ProductCategoryAPIController`, `ProductCategory` (tenant-scoped) |
| Brands | Fully implemented | `BrandAPIController`, `Brand` (tenant-scoped) |
| Units & Base Units | Fully implemented | `UnitAPIController`, `BaseUnitAPIController`, `Unit`, `BaseUnit` |
| Product variations | Fully implemented | `MainProduct` (`SINGLE_PRODUCT` / `VARIATION_PRODUCT`), `Variation`, `VariationType`, `VariationProduct` |
| Purchase & selling prices | Fully implemented | `products.product_cost`, `products.product_price` |
| Tax & discount | Fully implemented | Per-item and order-level tax (`EXCLUSIVE`/`INCLUSIVE`) & discount (`PERCENTAGE`/`FIXED`), `TaxesAPIController` |
| Product status (active/inactive) | Missing | No `status` / `is_active` toggle column on `products` or `main_products` |

## B. Inventory Management

| Feature | Classification | Evidence & Notes |
|---|---|---|
| Stock-in (via Purchase / Adjustment) | Partially implemented | `manageStock()` helper & `AdjustmentRepository`; purchases add stock regardless of `PENDING`/`ORDERED` status |
| Stock-out (via Sale / Adjustment) | Partially implemented | `SaleRepository` deducts stock without `lockForUpdate()`; `manageStock()` silently clamps negatives to `0` |
| Stock adjustments | Partially implemented | `AdjustmentAPIController`, `AdjustmentRepository`; subtraction on missing `ManageStock` row is silently ignored; deleting an addition item can drive stock negative |
| Stock history / ledger | Missing | No `stock_movements` or `stock_histories` table/audit trail exists; only current `manage_stocks.quantity` |
| Low-stock alerts | Fully implemented (with bug) | `ManageStock::boot()` sets `alert` flag against `product->stock_alert`; `DashboardAPIController::stockAlerts`, `ReportAPIController::stockAlerts`; crashes if `$product` is null |
| Out-of-stock handling | Partially implemented | `SaleRepository` throws `422` when `quantity > available`, `pos-settings` has `show_pos_stock_product`, but no DB row lock prevents race conditions |
| Stock valuation | Missing | No dedicated stock valuation (FIFO/LIFO/weighted average) endpoint or report |
| Inventory reports | Partially implemented | `ManageStockAPIController::stockReport`, Excel export `stockReportExcel`; lacks tenant isolation on `warehouse_id` |

## C. Purchase Management

| Feature | Classification | Evidence & Notes |
|---|---|---|
| Purchase creation | Partially implemented | `PurchaseAPIController`, `PurchaseRepository::storePurchase()`; allows negative quantity (`== 0` check instead of `<= 0`) and adds stock even when status is `PENDING`/`ORDERED` |
| Supplier selection | Fully implemented | `SupplierAPIController`, `Supplier` (tenant-scoped) |
| Purchase items & quantity & cost | Fully implemented | `PurchaseItem`, `calculationPurchaseItems()` |
| Purchase status (`RECEIVED`/`PENDING`/`ORDERED`) | Partially implemented | Stored on `purchases.status`, but stock is mutated unconditionally on create |
| Payment status | Partially implemented | `payment_status`, `paid_amount`, `partial_amount` stored on `purchases`, no separate `purchase_payments` ledger |
| Purchase returns | Partially implemented | `PurchaseReturnAPIController`, `PurchaseReturnRepository`; lacks tenant isolation (`PurchaseReturn` has no `Multitenantable`) |

## D. Sales Management

| Feature | Classification | Evidence & Notes |
|---|---|---|
| Sales creation | Partially implemented | `SaleAPIController`, `SaleRepository::storeSale()`; mail/SMS inside DB transaction; hold deleted before sale commits |
| Sales history | Fully implemented (with perf issue) | `SaleAPIController::index()`; severe N+1 queries and full-table `LIKE '%%'` scans |
| Customer management | Fully implemented | `CustomerAPIController`, `Customer` (tenant-scoped), CSV import |
| Sales invoices (PDF) | Fully implemented | `SaleAPIController::pdfDownload` (English & Arabic Blade PDFs via DomPDF/mPDF) |
| Discounts & Taxes | Fully implemented | Item-level & order-level percentage/fixed discounts and inclusive/exclusive taxes |
| Payment records (split/partial) | Partially implemented | `SalesPayment`, `SalesPaymentAPIController`; overpayment/change handling in `SaleRepository` marks overpayment as `PARTIAL_PAID` if `amount != grand_total` |
| Sales returns & refunds | Partially implemented | `SaleReturnAPIController`, `SaleReturnRepository`; lacks tenant scoping on `SaleReturn` model; commented-out duplicate return check allows returning more than sold across multiple returns |

## E. Multi-Store / Warehouse

| Feature | Classification | Evidence & Notes |
|---|---|---|
| Store management (Multi-store SaaS) | Broken (Critical Security Bug) | `StoreAPIController` & `StoreRepository` implement multi-store switching via `tenants` + `stores` + `user_stores`, **but `changeStore`, `show`, `update`, `destroy`, `changeStatus` have ZERO ownership checks** — any user can switch into or delete another tenant's store |
| Warehouse management | Fully implemented | `WarehouseAPIController`, `Warehouse` (tenant-scoped per active store) |
| Store-specific stock | Partially implemented | Stock is per `warehouse_id` in `manage_stocks`; `Warehouse` is tenant-scoped, but `ManageStock` itself is not tenant-scoped |
| Stock transfers (between warehouses) | Partially implemented (Security Bug) | `TransferAPIController`, `TransferRepository`; route duplicated outside `permission:manage_transfers`; no check preventing `from_warehouse_id == to_warehouse_id` |
| Transfer history | Fully implemented | `TransferAPIController::index()` (tenant-scoped via `Transfer` model) |
| Store-specific reports | Partially implemented | Most reports filter via tenant-scoped models or `whereHas('warehouse')`, but Excel exports overwrite shared files across tenants and `ManageStockAPIController::stockReport` accepts arbitrary `warehouse_id` |
