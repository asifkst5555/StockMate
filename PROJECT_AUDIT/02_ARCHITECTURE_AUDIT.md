# Phase 2: Laravel Architecture Audit

## 1. Architecture Overview

The application uses a layered API architecture:
- **Routes**: `routes/api.php` (`/api/*`), `routes/sadmin.php` (`/api/sadmin/*`), `routes/web.php`, and `routes/upgrade.php`.
- **Controllers**: Thin-to-medium API controllers extending `AppBaseController`, delegating persistence to `prettus/l5-repository` repositories.
- **Models & Serialization**: Custom JSON:API-style serialization via `JsonResourceful` / `HasJsonResourcefulData` (`prepareAttributes()`, `prepareLinks()`).
- **Multi-Tenancy**: Single-database isolation using `App\Traits\Multitenantable` global scope (`where('tenant_id', Auth::user()->tenant_id)`) + `stancl/tenancy` `BelongsToTenant`.

## 2. Verified Architectural Defects

### A. Broken Imports, Missing Returns & Runtime Crashes
1. **Broken Namespace Reference in `HoldAPIController::destroy()`** (`app/Http/Controllers/API/HoldAPIController.php:98`):
   - `catch (Exception $e)` without `use Exception;` resolves to `App\Http\Controllers\API\Exception`, so real exceptions are never caught and transactions are not rolled back.
2. **Broken Query Logic in `HoldAPIController::update()`** (`app/Http/Controllers/API/HoldAPIController.php:72`):
   - `Hold::whereId($id)->first()->value('reference_code')` invokes `value()` on the model instance, executing a fresh unscoped query and returning the first hold's `reference_code` in the table rather than record `$id`.
3. **Missing Return Statement in `AuthController::isValidToken()`** (`app/Http/Controllers/API/AuthController.php:285-295`):
   - When no Bearer token is provided, the method returns nothing despite `: JsonResponse` return type, throwing a `TypeError`. Also passes boolean `$valid` into translation helper `__($valid)`.
4. **Null Dereference Risks in `AuthController::login()`** (`app/Http/Controllers/API/AuthController.php:80-111`):
   - `User::whereRaw('lower(email) = ?', [$email])` does not lowercase `$email` before comparison.
   - `Language::where('iso_code', $user->language)->first()->id` crashes if language row does not exist.
   - `$user->roles[0]->name` crashes if user has no assigned role.
5. **Null Dereference Risks in `helpers.php`**:
   - `getCurrencyCode()` (line 110) and `getLogo()` (line 199) dereference `->first()->value` / `->first()->symbol` without null checks.
6. **Null Dereference & Log Spam in `ManageStock::boot()`** (`app/Models/ManageStock.php:77-99`):
   - Dereferences `$product->stock_alert` without checking `$product !== null`, and writes `Log::info()` twice on every single stock create/update.
7. **Null Dereference & Duplicate Assignments in `POSRegisterAPIController`** (`app/Http/Controllers/API/POSRegisterAPIController.php:55-95`):
   - Duplicate assignments on lines 56–61; unvalidated `$input['cash_in_hand_while_closing']` and `$input['notes']`; `$register->closed_at->toDateTimeString()` crashes if register is still open or missing.
8. **PHP 8.2+ Dynamic Property Deprecation in `ReportAPIController`** (`app/Http/Controllers/API/ReportAPIController.php:76`):
   - `$this->customerRepository` is assigned in `__construct()` without being declared as a class property.
9. **Wrong Filename Checked Before Deletion in `ReportAPIController`** (`app/Http/Controllers/API/ReportAPIController.php:81,93`):
   - Checks `excel/sale-report-pdf.xlsx` and `excel/purchase-report-pdf.xlsx` instead of `-excel.xlsx`.

### B. Route Defects (`routes/api.php` & `routes/upgrade.php`)
1. **Duplicate Route Name**: `razorpay/generate-session` (line 76) reuses `->name('stripe-generate-session')`, colliding with line 74 (`stripe/generate-session`).
2. **Duplicate Route Bypassing Permission Middleware**: `Route::resource('transfers', TransferAPIController::class)` is declared inside `permission:manage_transfers` (line 161) and then declared **again unprotected** on line 172, overwriting the protected route.
3. **Duplicate Resource Route**: `Route::resource('settings', SettingAPIController::class)` is declared twice (lines 255 and 258).
4. **Unprotected Upgrade Route**: `routes/upgrade.php` exposes `GET /upgrade-to-v1-2-0` which runs `Artisan::call('migrate', ['--force' => true])` with no authentication and no `UPGRADE_MODE` check.

### C. Incomplete Multi-Tenancy & Broken Scope Hygiene
1. **Unqualified `tenant_id` in `Multitenantable` Trait** (`app/Traits/Multitenantable.php:14`):
   - `$builder->where('tenant_id', Auth::user()->tenant_id)` omits the table prefix (`$builder->getModel()->getTable() . '.tenant_id'`), risking `Column 'tenant_id' in where clause is ambiguous` on joined queries.
2. **Core Business Models Missing `Multitenantable` & `tenant_id`**:
   - `Purchase`, `PurchaseReturn`, `SaleReturn`, `Expense`, `Hold`, `ManageStock`, and `CouponCode` do not have `tenant_id` columns or `Multitenantable`. Several controllers (`PurchaseAPIController`, `SaleReturnAPIController`, `HoldAPIController`, `ExpenseAPIController`, `ManageStockAPIController`) do not consistently enforce tenant scoping on `show`, `edit`, `update`, `destroy`, or fallback `index` queries.

### D. Performance Bottlenecks & N+1 Queries
1. **Full Table Loads on Empty Search** in `SaleAPIController::index()`, `PurchaseAPIController::index()`, `SaleReturnAPIController::index()`:
   - Calls `Customer::where('name', 'LIKE', "%$search%")->get()->count()` (loading entire tables into PHP memory!) even when `$search === ''`, then runs redundant `whereHas` subqueries.
2. **Severe N+1 in `Sale::prepareAttributes()`** (`app/Models/Sale.php:209-299`):
   - Calls `$this->dueAmount($this->id)` (2 extra SQL queries per sale row) plus lazy-loads `customer`, `warehouse`, `user`, `saleItems`, and `payments` for every row in paginated collections.
3. **Synchronous Mail & SMS Calls Inside DB Transactions** (`SaleRepository::storeSale()`, `SaleReturnRepository::storeSaleReturn()`):
   - External SMTP and Guzzle HTTP requests run inside `DB::beginTransaction() ... DB::commit()`, holding row locks and rolling back completed sales if mail/SMS fails.
4. **Shared Export Filenames Across Tenants** (`ReportAPIController`):
   - Writes Excel reports to static paths (`excel/sale-report-excel.xlsx`), causing cross-tenant overwrite race conditions.
5. **MySQL-Only SQL in Dashboard** (`DashboardAPIController`):
   - `DATE_FORMAT(date, "%Y-%m-%d")` and `selectRaw('products.*, ...')` with `groupBy('products.id')` require MySQL with `strict => false` (`ONLY_FULL_GROUP_BY` disabled).
