# Phase 6: Security Audit

## 1. Critical & High-Severity Findings

| Severity | Finding | Location | Details |
|---|---|---|---|
| **CRITICAL** | Cross-Tenant Account Takeover (IDOR) in Store Switching | `app/Http/Controllers/StoreAPIController.php:97-106` (`POST /api/change-store/{store}`) | Updates `auth()->user()->tenant_id = $store->tenant_id` with **zero** ownership or `UserStore` membership verification. Any authenticated user can switch into ANY other tenant's store and access all their data. |
| **CRITICAL** | Cross-Tenant IDOR on Store CRUD & Status | `app/Http/Controllers/StoreAPIController.php:64-140` (`show`, `update`, `destroy`, `changeStatus`) | No authorization check verifying `$store->user_id === auth()->id()` or `UserStore` assignment. Any user can view, rename, disable, or **permanently delete** another tenant's store and tenant database! |
| **CRITICAL** | Unauthenticated Database Migration Execution | `routes/upgrade.php:6-13` (`GET /upgrade-to-v1-2-0`) | Publicly accessible route executes `Artisan::call('migrate', ['--force' => true])` with no auth middleware and no `UPGRADE_MODE` guard. |
| **HIGH** | Unauthenticated Cache Clear Endpoint | `routes/api.php:443` (`GET /api/cache-clear`) | Publicly accessible DoS vector clearing the entire application cache without authentication. |
| **HIGH** | Permission Bypass via Duplicate Unprotected Route | `routes/api.php:160-172` | `Route::resource('transfers', ...)` is declared inside `permission:manage_transfers` (line 161) and re-declared **unprotected** on line 172, overwriting the protected route. |
| **HIGH** | Missing Tenant Isolation on Core Models | `Purchase`, `PurchaseReturn`, `SaleReturn`, `Expense`, `Hold`, `ManageStock`, `CouponCode` | No `tenant_id` or `Multitenantable` scope. `show`, `edit`, `update`, `destroy`, and `stockReport` allow IDOR access to other tenants' records by ID or `warehouse_id`. |
| **HIGH** | Missing Permission Middleware on Sensitive API Resources | `routes/api.php:135-159, 212-224, 252, 267-299, 304-437` | `products`, `main-products`, `variations`, `purchases`, `purchases-return`, `holds`, `quotations`, `taxes`, `payment-methods`, `sms-settings`, `mail-templates`, `sms-templates`, `coupon-codes`, and all report routes have no `permission:*` middleware (only `auth:sanctum`, `verified`, `check_subscription`). |
| **HIGH** | Super Admin Translation Update Exposed to All Users | `routes/sadmin.php:32-35` | `POST /api/sadmin/languages/translation/{language}/update` only requires `auth:sanctum` + `verified`, missing `role:superadmin`. Any tenant user can overwrite global language files. |
| **MEDIUM** | Committed Default `APP_KEY` & Weak Default Credentials | `.env.example`, `.env`, `database/seeders/DefaultUserSeeder.php` | `.env.example` contains a hardcoded `APP_KEY`, `DB_PASSWORD="root"`, and `DefaultUserSeeder` creates `superadmin@infy-pos.com` and `admin@infy-pos.com` with weak password `123456`. |
| **MEDIUM** | Mass Assignment of `tenant_id` | `app/Models/Sale.php:121` (and other tenant models) | `tenant_id` is included in `$fillable`. While `Multitenantable::saving` only sets `tenant_id` when empty, passing `tenant_id` in `$request->all()` to `Model::create($input)` could allow writing records into another tenant. |
| **MEDIUM** | Shared Excel Export Paths Across Tenants | `app/Http/Controllers/API/ReportAPIController.php:79-149` | All tenants write to the same public `storage/excel/*.xlsx` filenames, allowing cross-tenant report disclosure via predictable public URLs. |
| **MEDIUM** | SSRF / Unvalidated External Request in SMS Dispatch | `app/Repositories/SaleRepository.php:181-204` | Sends Guzzle POST to user-configured `SmsSetting` `url` without URL scheme/host allowlist validation. |
| **LOW** | Password Reset User Enumeration | `app/Http/Controllers/API/AuthController.php:194-197` | Explicitly returns `user_not_found` error when email does not exist. |

## 2. Security Controls Verified Present

- **CSRF**: `VerifyCsrfToken` active on `web` middleware group.
- **API Auth**: Laravel Sanctum (`auth:sanctum`) with token expiration (`SANCTUM_TTL=5000`) and email verification (`verified`).
- **Rate Limiting**: API throttled at 150 req/min (`RouteServiceProvider`), `forgot-password` throttled at `5,1`.
- **Password Hashing**: Uses `Hash::make()` (bcrypt).
- **SQL Injection**: Predominantly Eloquent/Query Builder parameterized queries; `whereRaw('lower(email) = ?', [$email])` uses parameter binding.

## 3. Remediation Priority (Safe Fixes to Apply Immediately)

1. Lock down `StoreAPIController` (`show`, `update`, `destroy`, `changeStore`, `changeStatus`) with strict ownership / `UserStore` authorization checks.
2. Guard `/upgrade-to-v1-2-0` with `config('app.upgrade_mode')` (matching `/upgrade/database`).
3. Remove duplicate unprotected `transfers` route in `routes/api.php`, fix duplicate `stripe-generate-session` route name, remove duplicate `settings` resource, and protect `cache-clear` behind `auth:sanctum` + `permission:manage_setting`.
4. Restrict `languages/translation/{language}/update` in `routes/sadmin.php` to `role:superadmin`.
5. Enforce tenant-scoped `whereHas('warehouse')` checks on `PurchaseAPIController`, `SaleReturnAPIController`, `ExpenseAPIController`, `HoldAPIController`, and `ManageStockAPIController`.
