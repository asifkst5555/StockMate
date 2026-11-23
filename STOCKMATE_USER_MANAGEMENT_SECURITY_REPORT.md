# StockMate — User Management Security Report & Remediation Plan

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Audit Scope:** User Management, Role/Permission Enforcement, Tenant Isolation, Privilege Escalation

---

## 1. Security Findings Summary

| ID | Severity | Vulnerability | Affected File(s) & Lines | Exploit Scenario |
|---|---|---|---|---|
| **SEC-01** | **CRITICAL** | **Vertical Privilege Escalation to Super Admin (or Store Admin)** | `CreateUserRequest.php:27`, `UpdateUserRequest.php:29`, `UserRepository.php:59-61,106-108` | Any Store Admin (or `manage_reports` user) sends `POST /api/users` with `role_id: 1` (`superadmin`) or `role_id: 2` (`admin`), creating a full Super Admin account directly from tenant API. |
| **SEC-02** | **CRITICAL** | **Cross-Tenant Account Takeover via Password Reset** | `UserRepository.php:228-244` (`updateUserPassword`) | `withoutGlobalScope('tenant')->find($input['user_id'])` never checks tenant ownership. Any Store Admin (or `manage_reports` user) can reset the password of **any other tenant's Store Admin** and log into their store. |
| **SEC-03** | **CRITICAL** | **Global Account Deactivation (Including Super Admin #1)** | `UserAPIController.php:277-284` (`statusUpdate`) | `User::withoutGlobalScope('tenant')->findOrFail($user_id)` has zero tenant, role, or self checks. Any Store Admin (or `manage_reports` user) can call `GET /api/users/status/1` and disable the Super Admin or any other tenant's users. |
| **SEC-04** | **CRITICAL** | **Cross-Tenant Self-Pivot via Mass Assignment in `updateProfile`** | `User.php:86-97`, `UserAPIController.php:133-139`, `UserRepository.php:148-170` | `User::$fillable` includes `tenant_id`, `status`, `password`, `email_verified_at`. `updateProfile()` passes `$request->all()` to `$user->update($input)` without stripping `tenant_id`. Any authenticated user (even a Cashier) can switch their own `tenant_id` to any victim tenant! |
| **SEC-05** | **CRITICAL** | **Cross-Tenant User Injection via Unvalidated `stores` Parameter** | `CreateUserRequest.php:28`, `UpdateUserRequest.php:30`, `UserRepository.php:69-86,117-133` | Neither request nor repository verifies that submitted `store_id`s belong to the caller's authorized stores. A malicious Store Admin can pass another tenant's `store_id` and inject a user into that tenant. |
| **SEC-06** | **CRITICAL** | **Over-Privileged Route Middleware (`manage_reports` Grants Full User CRUD)** | `routes/api.php:179-184` | `permission:manage_users\|manage_reports` wraps `POST/PUT/DELETE /api/users`, `statusUpdate`, and `changeUserPassword`. A report-only staff member can create/edit/delete users and reset passwords. |
| **SEC-07** | **CRITICAL** | **Cross-Tenant Role Read / Update / Delete** | `RoleAPIController.php:61-98` | `show()`, `update()`, and `destroy()` call `withoutGlobalScope('tenant')->findOrFail(...)` without checking whether the role's `tenant_id` belongs to the caller's stores. Tenant A can modify or delete Tenant B's custom roles. |
| **SEC-08** | **HIGH** | **Missing Fine-Grained Backend Permission Enforcement** | `routes/api.php:109-111,179-184` | Backend only checks coarse `manage_users` and `manage_roles`, ignoring seeded `create_users`, `edit_users`, `view_users`, `delete_users`, `create_roles`, `edit_roles`, `delete_roles`. UI hides buttons, but direct API calls succeed. |
| **SEC-09** | **HIGH** | **No Last Active Super Admin Protection & No Sanctioned Super Admin Creation** | `AdminAPIController.php:67-114`, `AdminRepository.php:63-114` | `AdminAPIController::destroy()` and `statusUpdate()` allow deleting or deactivating a Super Admin (including oneself or the last active Super Admin). No sanctioned UI/API exists for Super Admin creation. |
| **SEC-10** | **MEDIUM** | **Null Pointer 500 Errors on Out-of-Scope User IDs** | `UserAPIController.php:70,91-93,115-117` | `$user = $query->find($id)` is immediately dereferenced (`$user->id`) without null check, throwing 500 instead of 403/404. |
| **SEC-11** | **MEDIUM** | **Role Dropdown Exposes `Role::ADMIN` to Tenant Users** | `RoleAPIController.php:37,42,44`, `users/UserForm.js:502-513` | `GET /api/roles` appends `->orWhere('name', Role::ADMIN)`, allowing Store Admins and staff with `create_users` to see and select `admin` in the UI dropdown. |
| **SEC-12** | **LOW** | **State-Changing Action Exposed Over HTTP `GET`** | `routes/api.php:182`, `routes/sadmin.php:41-42` | `users/status/{user_id}`, `admin-users/status/{admin_user}`, and `admin-users/email-verify/{admin_user}` mutate database state over `GET` instead of `POST`/`PATCH`. |

---

## 2. Existing Automated Test Baseline (Verified Read-Only)
- Current PHPUnit test suite (`php artisan test`): **19 passed, 0 failed**.
- None of the 12 findings above are currently covered by automated security tests (existing tests cover `StoreAPIController`, `SecureFileController`, POS/inventory, auth, Quick Login, and Logout).

---

## 3. Recommended Remediation & Implementation Plan (Pending Approval)

### Phase 1 — Critical Backend Authorization & Tenant Isolation Fixes
1. **Route Middleware Hardening (`routes/api.php`)**:
   - Split `/api/users` routes:
     - `GET /api/users`, `GET /api/users/{user}` → `permission:view_users|manage_users|manage_reports`
     - `POST /api/users` → `permission:create_users|manage_users`
     - `PUT/PATCH/POST /api/users/{user}`, `GET/POST /api/users/status/{user_id}`, `POST /api/change-user-password` → `permission:edit_users|manage_users`
     - `DELETE /api/users/{user}` → `permission:delete_users|manage_users`
   - Split `/api/roles` write routes to enforce `create_roles|manage_roles`, `edit_roles|manage_roles`, `delete_roles|manage_roles`.
2. **Role & Store Authorization Service (`UserAPIController`, `UserRepository`, `CreateUserRequest`, `UpdateUserRequest`)**:
   - Introduce a centralized helper to resolve the caller's authorized `store_id`s and `tenant_id`s.
   - **Block `Role::SUPER_ADMIN` and `Role::ADMIN`** from ever being assigned through `/api/users` (`storeUser` & `updateUser`).
   - Validate that `role_id` belongs to the caller's authorized `tenant_id`s (and never exceeds caller's own permissions).
   - Validate that every `store_id` in `stores` belongs to the caller's authorized stores (return `403` otherwise).
   - Scope `show()`, `update()`, `destroy()`, `statusUpdate()`, and `updateUserPassword()` strictly to users inside the caller's authorized `tenant_id`s, returning `404`/`403` instead of null 500s, and always blocking modifications to Super Admins, Store Admin owners (by sub-users), or self.
3. **Mass Assignment Protection (`UserRepository::updateUserProfile`, `storeUser`, `updateUser`)**:
   - Explicitly allowlist only `['first_name', 'last_name', 'email', 'phone', 'image']` in `updateUserProfile()`.
   - Explicitly allowlist user fields in `storeUser()` and `updateUser()` so `tenant_id`, `status`, `email_verified_at` cannot be injected from request payloads.
4. **Role Tenant Isolation (`RoleAPIController`, `RoleRepository`)**:
   - Scope `show()`, `update()`, and `destroy()` to roles whose `tenant_id` is in the caller's authorized `tenant_id`s.
   - Remove `->orWhere('name', Role::ADMIN)` from tenant role assignment queries (or exclude `admin` from assignable dropdown roles for non-Super-Admins).
5. **Super Admin Safety Guards (`AdminAPIController`)**:
   - In `destroy()` and `statusUpdate()`: block deleting/deactivating oneself and block deleting/deactivating the last active `superadmin`.
   - Optionally add a dedicated, `role:superadmin`-only endpoint + UI option to create additional Super Admin accounts if desired.

### Phase 2 — Frontend Alignment
- Filter `admin` / `superadmin` out of `users/UserForm.js` role dropdown.
- Ensure `EditUser` / `User.js` hides edit/delete/status actions on higher-privilege accounts and on self.

### Phase 3 — Automated Security Regression Tests
- Add comprehensive PHPUnit tests covering all 12 findings (Super Admin escalation attempt → 403, `manage_reports` user creation attempt → 403, cross-tenant password reset → 403/404, cross-tenant status toggle & Super Admin status toggle → 403/404, `update-profile` `tenant_id` mass assignment → ignored, cross-tenant `stores` injection → 403, cross-tenant role edit/delete → 403/404, last active Super Admin deletion/deactivation → 422/403).
