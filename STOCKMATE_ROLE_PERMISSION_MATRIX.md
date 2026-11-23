# StockMate — Role & Permission Matrix Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Current vs. Recommended Role Hierarchy Matrix

| Capability | Super Admin (Current) | Super Admin (Recommended) | Store Admin (Current) | Store Admin (Recommended) | Staff / Cashier (Current) | Staff / Cashier (Recommended) |
|---|---|---|---|---|---|---|
| Create Super Admin | **No UI/API** | **Yes** (authorized Super Admin only, explicit endpoint + audit log) | **YES via API bug** (`POST /api/users` with `role_id=1`) | **NO** (hard backend block, 403) | **YES via API bug** if granted `manage_reports` or `manage_users` | **NO** (403) |
| Create Store Admin | **Yes** (`POST /api/sadmin/admin-users`) | **Yes** | **YES** (exposed in `GET /api/roles` + accepted by `POST /api/users` with `role_id=2`) | **NO** (only Super Admin creates Store Admins) | **YES** if granted `manage_users` or `manage_reports` | **NO** (403) |
| Create Staff / Cashier | Indirect (no tenant user screen) | Optional / Super Admin override | **Yes** (`POST /api/users`) | **Yes** (own stores only, non-admin roles only) | **YES** if granted `manage_users` OR `manage_reports` | **Only if explicitly granted `create_users`** (never admin/superadmin roles) |
| Edit / Deactivate Store Users | **Yes** (Store Admins via `/api/sadmin/admin-users`) | **Yes** (with last-Super-Admin & self-lockout guard) | **Yes** — AND can deactivate **ANY user globally including Super Admin #1** via `GET /api/users/status/{id}`! | **Yes** — strictly own store's non-admin users only; cannot edit/deactivate self or other tenants | **YES** if granted `manage_users` OR `manage_reports` (including disabling Super Admin #1!) | **Only if granted `edit_users`**, scoped to own store & lower privilege |
| Reset Another User's Password | **Yes** (`POST /api/sadmin/admin-users/change-password`) | **Yes** | **YES — across ALL tenants** via `POST /api/change-user-password` (only blocks Super Admin & self, NOT other tenants!) | **Only own store's users** (with `edit_users`) | **YES — across ALL tenants** if granted `manage_reports` or `manage_users`! | **NO** (unless granted `edit_users` for lower-privilege own-store staff) |
| Delete Users | **Yes** (Store Admins, **no last-Super-Admin guard**) | **Yes** (block deleting self & last active Super Admin) | **Yes** (own store tenants; 500 crash on cross-tenant ID) | **Yes** (own store non-admin users only, with `delete_users`; clean 403/404) | **YES** if granted `manage_users` OR `manage_reports` | **Only if granted `delete_users`**, own store non-admin users only |
| Manage Roles & Permissions | No Super Admin role screen | **Yes** (global roles & permissions) | **Yes** — AND can view/edit/delete **other tenants' custom roles** via `RoleAPIController`! | **Yes** — strictly own tenant's custom roles; cannot touch global `superadmin`/`admin` or other tenants' roles | **YES** if granted `manage_roles` (even with only `view_roles`) | **Only per fine-grained `view/create/edit/delete_roles`** |
| Modify Own Role / Tenant | Blocked on `update()`, **NOT blocked on `tenant_id` via `POST /api/update-profile`** | **Strictly blocked** | Blocked on `update()`, **VULNERABLE via `POST /api/update-profile` mass assignment (`tenant_id`)** | **Strictly blocked** (profile allowlist: name, email, phone, image only) | **VULNERABLE via `POST /api/update-profile` mass assignment (`tenant_id`)** | **Strictly blocked** |

---

## 2. Backend Permission Enforcement Audit (`routes/api.php` & `routes/sadmin.php`)

| Endpoint | HTTP Method | Current Middleware | Gap / Defect |
|---|---|---|---|
| `/api/sadmin/admin-users*` | All | `auth:sanctum`, `role:superadmin`, `verified` | Good role gate, but `destroy()` and `statusUpdate()` lack self-protection and last-active-Super-Admin protection. |
| `/api/users` | `GET` | `permission:manage_users\|manage_reports` | Acceptable for read-only list (needed for report filters), though should ideally be `view_users\|manage_users\|manage_reports`. |
| `/api/users` | `POST` | `permission:manage_users\|manage_reports` | **CRITICAL:** `manage_reports` should NEVER allow creating users! Should require `create_users\|manage_users` (and never `manage_reports`). |
| `/api/users/{user}` | `POST`/`PUT`/`PATCH` | `permission:manage_users\|manage_reports` | **CRITICAL:** `manage_reports` allows editing users! Should require `edit_users\|manage_users`. |
| `/api/users/{user}` | `DELETE` | `permission:manage_users\|manage_reports` | **CRITICAL:** `manage_reports` allows deleting users! Should require `delete_users\|manage_users`. |
| `/api/users/status/{user_id}` | `GET` | `permission:manage_users\|manage_reports` | **CRITICAL:** Uses `GET` for state mutation, allows `manage_reports`, and lacks tenant + Super Admin + self checks! Should be `POST`/`PATCH` with `edit_users\|manage_users`. |
| `/api/change-user-password` | `POST` | `permission:manage_users\|manage_reports` | **CRITICAL:** Allows `manage_reports` AND has zero tenant isolation! Should require `edit_users\|manage_users` + tenant ownership check. |
| `/api/roles` | `GET` | `auth:sanctum`, `verified`, `check_subscription` | Returns `Role::ADMIN` to Store Admins & staff, exposing it in the UI dropdown. |
| `/api/roles/{role}` | `GET`/`PUT`/`DELETE` | `permission:manage_roles` | Coarse middleware ignores `create_roles`/`edit_roles`/`delete_roles`, and controller lacks tenant ownership checks on `show`/`update`/`destroy`. |
| `/api/update-profile` | `POST` | `auth:sanctum`, `verified`, `check_subscription` | Passes `$request->all()` to `$user->update($input)` where `tenant_id`, `status`, `password`, `email_verified_at` are `$fillable`. |

---

## 3. Recommended Permission Architecture

1. **Split Read vs. Write User Routes**:
   - `GET /api/users`, `GET /api/users/{user}` → `permission:view_users|manage_users|manage_reports`
   - `POST /api/users` → `permission:create_users|manage_users`
   - `PUT/PATCH/POST /api/users/{user}`, `POST /api/users/status/{user_id}`, `POST /api/change-user-password` → `permission:edit_users|manage_users`
   - `DELETE /api/users/{user}` → `permission:delete_users|manage_users`
2. **Enforce Strict Role Ceiling (Privilege Hierarchy)**:
   - Nobody using `/api/users` (tenant space) may ever assign `superadmin` (`Role::SUPER_ADMIN`) or `admin` (`Role::ADMIN`), or any role whose `tenant_id` does not belong to the caller's authorized store tenants.
   - Only a **Super Admin** on `/api/sadmin/*` may create Store Admins or (via a dedicated guarded endpoint) another Super Admin.
   - A user with `create_users`/`edit_users` cannot assign a role that has permissions the caller does not themselves possess (no privilege escalation via role assignment).
3. **Strict Profile Allowlist**:
   - `updateProfile` must only accept `$request->only(['first_name', 'last_name', 'email', 'phone', 'image'])` — never `tenant_id`, `status`, `password`, `email_verified_at`, or `role_id`.
