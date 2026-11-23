# StockMate — User Management Security Remediation Changelog

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Scope:** Complete remediation of vulnerabilities `SEC-01` through `SEC-12` across User Management, Role Authorization, Tenant Isolation, and Public Registration.

---

## 1. Files Created

1. **`app/Services/UserAuthorizationService.php`**  
   Centralized backend authorization & tenant-scoping service enforcing:
   - `authorizedStoreIds(User $actor)` & `authorizedTenantIds(User $actor)`
   - `assertStoresAuthorized(User $actor, $stores)` (SEC-05)
   - `assertRoleAssignableByTenantUser(User $actor, int $roleId)` (SEC-01 — blocks `superadmin`, `admin`, cross-tenant roles, and privilege escalation beyond caller's permissions)
   - `findAuthorizedTenantUserOrFail(User $actor, int $targetUserId)` (SEC-02, SEC-03, SEC-10 — strict tenant scoping with clean 403/404 instead of null 500s)
   - `assertCanMutateTenantUser(User $actor, User $target)` (blocks self-mutation, Super Admin mutation, and non-Super-Admin mutation of Store Admins)
   - `assertRoleAccessibleByActor(User $actor, Role $role)` (SEC-07 — tenant ownership enforcement for roles)
   - `assertSuperAdminCanMutateTarget(User $actor, User $target, bool $isDeactivatingOrDeleting)` (SEC-09 — blocks self-deletion/deactivation and deletion/deactivation of the **last active Super Admin**)
2. **`tests/Feature/UserManagementSecurityTest.php`**  
   8 comprehensive PHPUnit feature test methods (using `DatabaseTransactions` so live DB records remain untouched) covering all 15 mandatory security scenarios.

---

## 2. Files Modified

### Routes & Middleware
- **`routes/api.php`**:
  - **SEC-06 & SEC-08:** Split `/api/users` routes so `manage_reports` is strictly read-only (`GET /api/users`, `GET /api/users/{user}`), while writes enforce fine-grained backend permissions: `POST /api/users` (`create_users|manage_users`), `PUT/PATCH/POST /api/users/{user}` & `POST /api/users/status/{user_id}` & `POST /api/change-user-password` (`edit_users|manage_users`), and `DELETE /api/users/{user}` (`delete_users|manage_users`).
  - **SEC-08:** Split `/api/roles` routes to enforce `create_roles|manage_roles`, `view_roles|manage_roles`, `edit_roles|manage_roles`, and `delete_roles|manage_roles`.
  - **SEC-12:** Switched `users/status/{user_id}` from `GET` to `POST`.
  - **Public Registration Hardening:** Added `throttle:10,1` rate limiting on `POST /api/register`.
- **`routes/sadmin.php`**:
  - Added dedicated Super Admin management workflow under `auth:sanctum`, `role:superadmin`, `verified`:
    - `GET /api/sadmin/super-admins`
    - `POST /api/sadmin/super-admins`
    - `POST /api/sadmin/super-admins/{admin_user}/status`
    - `DELETE /api/sadmin/super-admins/{admin_user}`
  - **SEC-12:** Switched `admin-users/email-verify/{admin_user}` and `admin-users/status/{admin_user}` from `GET` to `POST`.

### Controllers, Repositories & Requests
- **`app/Http/Controllers/API/UserAPIController.php`**: Wired `UserAuthorizationService` into `store`, `show`, `update`, `destroy`, `changeUserPassword`, and `statusUpdate`; added strict field allowlisting on `store`, `update`, `updateProfile`, `changePassword`, and `changeUserPassword`.
- **`app/Repositories/UserRepository.php`**: Enforced explicit safe field allowlists in `storeUser`, `updateUser`, `updateUserProfile` (SEC-04 — blocks mass assignment of `tenant_id`, `status`, `password`, `email_verified_at`), and `updateUserPassword`.
- **`app/Http/Controllers/API/RoleAPIController.php`** & **`app/Repositories/RoleRepository.php`**:
  - **SEC-07:** Enforced `assertRoleAccessibleByActor` on `show`, `update`, and `destroy`.
  - **SEC-11:** Filtered `GET /api/roles` to caller's authorized tenant custom roles only, excluding `Role::SUPER_ADMIN` and `Role::ADMIN`.
  - Fixed `RoleRepository::updateRole` to resolve authorized roles via `withoutGlobalScope('tenant')->findOrFail($id)`.
- **`app/Http/Controllers/Sadmin/AdminAPIController.php`** & **`app/Repositories/AdminRepository.php`**:
  - Added `superAdmins()` and `storeSuperAdmin()` for authorized Super Admin creation (`tenant_id = null`, `Role::SUPER_ADMIN`).
  - **SEC-09:** Enforced `assertSuperAdminCanMutateTarget` on `destroy()` and `statusUpdate()`.
  - Added explicit field allowlisting on `store`, `storeSuperAdmin`, and `update`.
- **`app/Http/Controllers/API/AuthController.php`** & **`app/Http/Requests/RegisterRequest.php`**:
  - Explicitly allowlisted public registration fields (`full_name`, `email`, `password`, `phone`, `region`), enforced `password` min length 6, ignored any client-supplied `role_id`/`tenant_id`/`store_id`/`status`, and always assigned `Role::ADMIN` server-side.

### Frontend React UI
- **`resources/pos/src/store/action/admin/adminUsersAction.js`**: Updated `verifyUserEmail` and `changeUserStatus` from `GET` to `POST` (SEC-12).
- **`resources/pos/src/components/admin/users/index.jsx`**: Added StockMate-themed **"Manage Super Admins"** button & modal on Super Admin's Users screen to list, create, toggle status, and delete Super Admin accounts via `/api/sadmin/super-admins`.
- **`resources/pos/src/components/users/UserForm.js`**: Filtered `superadmin` and `admin` out of the tenant role selector as frontend defense-in-depth (SEC-11).
- Recompiled production bundle (`npm run prod`).

---

## 3. Database Safety Confirmation
- Existing accounts (`id: 1 superadmin@stockmate.com`, `id: 2 admin@stockmate.com`), roles, permissions, stores, and subscriptions were **100% preserved untouched**.
