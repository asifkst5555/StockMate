# StockMate — Tenant & User Isolation Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. How Users, Stores, and Tenants Are Connected

StockMate uses **single-database multi-tenancy** via `stancl/tenancy` (`BelongsToTenant`) + custom `App\Traits\Multitenantable`:

1. **Global Super Admin**:
   - `users.tenant_id = NULL`, assigned Spatie role `superadmin` (`roles.id = 1`, `roles.tenant_id = NULL`).
   - `MultiTenantMiddleware` bypasses tenancy initialization when `Auth::user()->hasRole(Role::SUPER_ADMIN)`.
   - Separated from tenant users by `tenant_id IS NULL`.
2. **Store Admin (Tenant Owner)**:
   - Owns one or more rows in `stores` (`stores.user_id = $admin->id`).
   - Each `Store` has a 1-to-1 `MultiTenant` record (`tenants.store_id = $store->id`, `stores.tenant_id = $tenant->id`).
   - `users.tenant_id` holds the Store Admin's **currently active store's `tenant_id`** (switched via `POST /api/change-store/{store}`).
   - Can belong to / own **multiple stores** (up to `plan.no_of_stores`).
3. **Store Staff / Sub-Users**:
   - Assigned to one or more stores via the pivot table `user_store` (`user_id`, `store_id`).
   - `users.tenant_id` holds their currently active store's `tenant_id`.
   - Can belong to **multiple stores** (within their employer's stores).
4. **Global Scope (`App\Traits\Multitenantable`)**:
   - Automatically adds `WHERE <table>.tenant_id = Auth::user()->tenant_id` on queries when `Auth::hasUser()` is true, and sets `tenant_id` on `saving` if empty.
   - **Critical caveat:** Several user and role controller/repository methods call `->withoutGlobalScope('tenant')` to support multi-store Store Admins — and in multiple places **fail to re-apply an authorized-tenant allowlist**, completely breaking tenant isolation!

---

## 2. Tenant Isolation Verification Results

| Component / Endpoint | Uses `withoutGlobalScope('tenant')`? | Re-applies Authorized Tenant Filter? | Isolation Verdict |
|---|---|---|---|
| `UserRepository::getUsers()` | Yes (for `Role::ADMIN`) | Yes — restricts to `Store::where('user_id', $storeUserId)->pluck('tenant_id')` | **PASS** (scoped to owner's stores) |
| `UserAPIController::show()` | Yes | Yes — `whereIn('tenant_id', $allStoreTenants)`, but returns `null` → 500 crash on miss instead of 404/403 | **PARTIAL (Bug: 500 on miss)** |
| `UserAPIController::update()` | Yes | Yes for finding `$user` — **BUT `UserRepository::updateUser()` does NOT validate `$input['stores']` or `$input['role_id']`!** | **FAIL (Cross-tenant store & role injection)** |
| `UserAPIController::store()` | N/A | **NO** — `UserRepository::storeUser()` accepts ANY `store_id` in `$input['stores']` and sets `$user->tenant_id = $store->tenant_id`! | **FAIL (CRITICAL — Cross-tenant user injection)** |
| `UserAPIController::destroy()` | Yes | Yes — `whereIn('tenant_id', $allStoreTenants)`, but returns `null` → 500 crash on miss | **PARTIAL (Bug: 500 on miss)** |
| `UserAPIController::statusUpdate()` | **YES** (`User::withoutGlobalScope('tenant')->findOrFail($user_id)`) | **NO!** Zero tenant check, zero role check! | **FAIL (CRITICAL — Can disable Super Admin & any tenant's users)** |
| `UserRepository::updateUserPassword()` | **YES** (`$this->withoutGlobalScope('tenant')->find($input['user_id'])`) | **NO!** Only checks `!= SUPER_ADMIN` and `!= self`; zero tenant check! | **FAIL (CRITICAL — Cross-tenant password reset / account takeover)** |
| `UserRepository::updateUserProfile()` | N/A (`Auth::user()`) | **NO!** Mass-assigns `$request->all()` where `tenant_id` is in `User::$fillable`! | **FAIL (CRITICAL — Self-pivot into any other tenant)** |
| `RoleAPIController::index()` | Yes | Yes — `whereIn('tenant_id', $stores)->orWhere('name', Role::ADMIN)` | **PARTIAL** (exposes global `admin` role in dropdown) |
| `RoleAPIController::show()` | **YES** | **NO!** Can read any tenant's roles & permissions | **FAIL (HIGH — Cross-tenant role read)** |
| `RoleAPIController::update()` | **YES** | **NO!** Only checks `!= admin` and `!= superadmin`; zero tenant check! | **FAIL (CRITICAL — Cross-tenant role modification)** |
| `RoleAPIController::destroy()` | **YES** | **NO!** Zero tenant check! | **FAIL (HIGH — Cross-tenant role deletion)** |
| `StoreAPIController::show/update/destroy/changeStore` | N/A | Yes — `authorizeStoreAccess()` enforces owner/assignment check (previously hardened & tested) | **PASS** |
| `AdminAPIController` (`/api/sadmin/admin-users`) | Yes | Protected by `role:superadmin` middleware; global scope bypass is intentional for Super Admin | **PASS** (needs last-Super-Admin guard) |

---

## 3. Data Integrity & Existing Accounts Check
- **Existing User IDs & Roles:** User #1 (`superadmin@stockmate.com`, `superadmin`, `tenant_id = NULL`) and User #2 (`admin@stockmate.com`, `admin`, `tenant_id = f58e82d5-2dd0-460a-be33-2a6b8cda9ad1`) are cleanly separated and intact.
- **Store Relationship:** Store #1 (`My Store`) is linked to `user_id = 2` and `tenant_id = f58e82d5-2dd0-460a-be33-2a6b8cda9ad1`.
- **No database mutations** were performed during this audit.
