# StockMate — User Management Module Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Audit Mode:** Read-Only (No application code, roles, permissions, users, or database records were modified)

---

## 1. Executive Summary — Who Can Currently Create Each Account Type?

| Account Type | Intended Creator | Actual Current Creator (Verified in Source Code) | Risk Level |
|---|---|---|---|
| **Super Admin** (`role: superadmin`, ID `1`) | Authorized Super Admin only | **No sanctioned UI or API exists.** However, **CRITICAL BUG:** Any authenticated Store Admin — OR any Staff/Cashier granted only `manage_reports` — can create a **Super Admin** by sending `role_id: 1` to `POST /api/users`, or escalate any store user to Super Admin via `POST /api/users/{user}`! | **CRITICAL** |
| **Store Admin** (`role: admin`, ID `2`) | Super Admin (and optionally public SaaS self-signup if enabled) | 1. **Super Admin** via `POST /api/sadmin/admin-users` (`AdminAPIController::store` → `AdminRepository::storeAdmin`, hardcoded `Role::ADMIN` + new Store + Tenant + Subscription).<br>2. **Any unauthenticated public user** via `POST /api/register` (`AuthController::register`, assigns `Role::ADMIN` + new Store + Tenant + Free Plan).<br>3. **Any Store Admin or `manage_reports` user** via `POST /api/users` with `role_id: 2` (`Role::ADMIN` is even exposed in the Store Admin's `GET /api/roles` dropdown!). | **CRITICAL / HIGH** |
| **Staff / Cashier / Custom Role Users** | Store Admin (or authorized user manager) within their own store | Any user with `manage_users` **OR `manage_reports`** via `POST /api/users` (`UserAPIController::store`). Fine-grained `create_users` permission is checked only in React UI, **not** enforced on the backend. | **HIGH** |

---

## 2. Architecture & Complete File Map

### A. Super Admin User Management (Global / SaaS Tenant Owners)
- **Routes (`routes/sadmin.php`, prefix `/api/sadmin`, middleware `auth:sanctum`, `role:superadmin`, `verified`)**:
  - `GET/POST/PUT/DELETE /api/sadmin/admin-users` → `App\Http\Controllers\Sadmin\AdminAPIController`
  - `GET /api/sadmin/admin-users/email-verify/{admin_user}` → `AdminAPIController::emailVerify`
  - `GET /api/sadmin/admin-users/status/{admin_user}` → `AdminAPIController::statusUpdate`
  - `POST /api/sadmin/admin-users/change-password` → `UserAPIController::changeUserPassword`
- **Repository & Requests**:
  - `app/Repositories/AdminRepository.php` (`storeAdmin`, `updateAdmin`, `getUsers`, `defaultSettings`)
  - `app/Http/Requests/CreateAdminRequest.php`, `app/Http/Requests/UpdateAdminRequest.php`
- **React Components**:
  - `resources/pos/src/components/admin/users/index.jsx` (list, status toggle, email verify, password change, delete)
  - `resources/pos/src/components/admin/users/CreateAdminUser.jsx`, `EditAdminUser.jsx`, `UserForm.jsx`, `AdminUserDetail.jsx`, `DeleteUser.jsx`
- **Actual Behavior**:
  - `AdminRepository::getUsers()` lists only users where `tenant_id != null` AND role is `admin` (`Role::ADMIN`). It does **not** list Super Admins or store staff.
  - `AdminRepository::storeAdmin()` creates a `User`, hardcodes `$user->assignRole(Role::ADMIN)`, creates a `Store` ("My Store"), creates a `MultiTenant`, attaches a `Subscription`, and seeds `defaultSettings($tenant->id)`.
  - There is **no role selector** in `admin/users/UserForm.jsx` and **no endpoint** to create another Super Admin.

### B. Store / Tenant User Management (Sub-Users / Staff / Cashiers)
- **Routes (`routes/api.php`, prefix `/api`, middleware `auth:sanctum`, `verified`, `check_subscription`, `permission:manage_users|manage_reports`)**:
  - `GET/POST/PUT/DELETE /api/users` → `App\Http\Controllers\API\UserAPIController`
  - `POST /api/users/{user}` → `UserAPIController::update`
  - `GET /api/users/status/{user_id}` → `UserAPIController::statusUpdate`
  - `POST /api/change-user-password` → `UserAPIController::changeUserPassword`
- **Self-Profile Routes (outside `permission:manage_users`, inside `check_subscription`)**:
  - `GET /api/edit-profile` → `UserAPIController::editProfile`
  - `POST /api/update-profile` → `UserAPIController::updateProfile`
  - `PATCH /api/change-password` → `UserAPIController::changePassword`
- **Repository & Requests**:
  - `app/Repositories/UserRepository.php` (`storeUser`, `updateUser`, `updateUserProfile`, `getUsers`, `updatePassword`, `updateUserPassword`)
  - `app/Http/Requests/CreateUserRequest.php`, `UpdateUserRequest.php`, `UpdateUserProfileRequest.php`, `UpdateChangeUserPasswordRequest.php`
- **React Components**:
  - `resources/pos/src/components/users/User.js`, `CreateUser.js`, `EditUser.js`, `UserForm.js`, `UserDetail.js`, `DeleteUser.js`

### C. Role & Permission Management
- **Routes (`routes/api.php`, inside `check_subscription`)**:
  - `GET /api/permissions` → `PermissionController::getPermissions` (no permission middleware)
  - `GET /api/roles` → `RoleAPIController::index` (no permission middleware; overrides resource index)
  - `POST/GET(show)/PUT/DELETE /api/roles` → `RoleAPIController` (middleware `permission:manage_roles`)
- **Models & Repository**:
  - `app/Models/Role.php`, `app/Repositories/RoleRepository.php`
  - React: `resources/pos/src/components/roles/Role.js`, `CreateRole.js`, `EditRole.js`, `RoleForm.js`, `DeleteRole.js`

---

## 3. End-to-End User Creation Trace (Frontend → Database)

### Flow 1: Super Admin Creates a Store Admin
1. Super Admin opens `/app/admin/users/create` (`admin/users/UserForm.jsx`) → enters Name, Email, Phone, Password, Plan (no role dropdown).
2. Submits `POST /api/sadmin/admin-users` → passes `auth:sanctum`, `role:superadmin`, `verified`, `CreateAdminRequest`.
3. `AdminAPIController::store()` calls `AdminRepository::storeAdmin($input)`.
4. Inside DB transaction: hashes password → `User::create($input)` → `$user->assignRole('admin')` → creates `Store` (`user_id = $user->id`) → creates `MultiTenant` (`store_id = $store->id`) → sets `tenant_id` on `Store` and `User` → creates `Subscription` → seeds default settings/customer/warehouse.

### Flow 2: Store Admin Creates a Store User
1. Store Admin opens `/app/user/users/create` (`users/UserForm.js`) → fetches roles via `GET /api/roles` (which returns tenant roles **plus** `Role::ADMIN`!) and stores via Redux `stores` state.
2. Submits `POST /api/users` → passes `auth:sanctum`, `verified`, `check_subscription`, `permission:manage_users|manage_reports`, `CreateUserRequest` (`role_id => 'exists:roles,id'`, `stores => ['required']`).
3. `UserAPIController::store()` passes `$request->all()` directly to `UserRepository::storeUser($input)`.
4. `UserRepository::storeUser()`:
   - Calls `$this->create($input)` (mass-assignable `tenant_id`, `status`, etc.).
   - Calls `$user->assignRole($input['role_id'])` — **accepts ANY role ID in `roles` table, including `1` (`superadmin`) or `2` (`admin`) or another tenant's role!**
   - Splits `$input['stores']` string and inserts into `user_store` without checking if the caller owns those `store_id`s!
   - Sets `$user->tenant_id` to `$store->tenant_id` of `stores[0]` — allowing cross-tenant user injection!

### Flow 3: Public Self-Registration
1. Any unauthenticated visitor calls `POST /api/register` (`AuthController::register`).
2. Creates a `User`, assigns `Role::ADMIN`, creates a new `Store` + `MultiTenant` + Free `Subscription` + default settings, and returns a Sanctum Bearer token.

---

## 4. Existing Database Accounts (Verified Read-Only)

| User ID | Name | Email | Role | `tenant_id` | Status | Owned Store |
|---|---|---|---|---|---|---|
| `1` | Super Admin | `superadmin@stockmate.com` | `superadmin` (ID `1`) | `NULL` (global) | `1` (Active) | None |
| `2` | admin admin | `admin@stockmate.com` | `admin` (ID `2`) | `f58e82d5-2dd0-460a-be33-2a6b8cda9ad1` | `1` (Active) | ID `1` (`My Store`) |

- Total `roles` in DB: **2** (`1: superadmin`, `2: admin`).
- Total `permissions` in DB: **109** (27 `manage_*` coarse permissions + 82 fine-grained `create_*`/`edit_*`/`view_*`/`delete_*` permissions).
- `user_store` table: currently **0 rows** (Store Admin owns Store #1 via `stores.user_id = 2`; `user_store` is populated for sub-users).

---

## 5. Frontend Audit Observations

1. **Super Admin UI (`admin/users/UserForm.jsx`)**:
   - Can create Store Admins with plan assignment, verify email, toggle status, change password, and delete.
   - Cannot create or view other Super Admins.
   - No self-protection or last-Super-Admin guard on delete/status toggle.
2. **Store Admin UI (`users/User.js` & `users/UserForm.js`)**:
   - Properly hides Create/Edit/Delete/Status controls using `getPermission(allConfigData?.permissions, Permissions.CREATE_USERS / EDIT_USERS / DELETE_USERS)` — **but these are UI-only checks not enforced by backend routes.**
   - **Role Dropdown Exposure**: `GET /api/roles` explicitly includes `->orWhere('name', Role::ADMIN)` (`RoleAPIController.php:37,42,44`), so `admin` appears in the role dropdown for Store Admins and sub-users with `create_users`!
   - **Store Selection**: Populated from caller's Redux `stores` slice (UI-restricted), but backend accepts arbitrary `store_id`s.
3. **Edit User Bug (`users/UserForm.js:80-88`)**:
   - The `disabled` check on the Save button does not include `selectStore` changes, and `singleUser[0].role_id.label === userValue.role_id.label` can behave unexpectedly when `role_id` is initialized as a scalar value (`singleUser[0].role_id.value`).
