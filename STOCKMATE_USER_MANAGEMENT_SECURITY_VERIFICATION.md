# StockMate — User Management Security Verification Report

**Date:** 2026-11-23  
**Environment:** Local Docker (`digital_inventory_pos_app` PHP 8.2.36 / Laravel 10.49.0 + `digital_inventory_pos_mysql` MySQL 8.0) at `http://127.0.0.1:8000`

---

## 1. Automated Test Results (`php artisan test`)

**Result: 27 passed, 0 failed (`1.13s`)**

### New Security Regression Suite (`Tests\Feature\UserManagementSecurityTest` — 8/8 Passed)
1. `test_super_admin_can_create_store_admin_and_another_super_admin` — **PASS**
2. `test_store_admin_cannot_create_super_admin_or_assign_global_admin_roles` — **PASS**
3. `test_staff_with_manage_reports_or_view_users_only_cannot_mutate_users` — **PASS**
4. `test_public_registration_always_assigns_store_admin_and_ignores_escalation_fields` — **PASS**
5. `test_user_cannot_change_own_role_or_tenant_id` — **PASS**
6. `test_cross_tenant_isolation_for_passwords_status_stores_and_roles` — **PASS**
7. `test_last_active_super_admin_cannot_be_deleted_or_deactivated` — **PASS**
8. `test_authorized_store_admin_retains_full_own_store_user_management` — **PASS**

### Existing Regression Suite (`SecurityAndPosAuditTest`, `ExampleTest` — 19/19 Passed)
All 19 existing tests covering POS checkout, stock movements, transfers, returns, valuation, `SecureFileController`, Quick Login, Logout, StockMate contact/WhatsApp links, and USD/BDT currency configuration continue to pass.

---

## 2. Vulnerability Closure Matrix (`SEC-01` – `SEC-12`)

| ID | Vulnerability | Fix Verified By | Status |
|---|---|---|---|
| **SEC-01** | Privilege escalation via arbitrary `role_id` | `test_store_admin_cannot_create_super_admin_or_assign_global_admin_roles` (HTTP 403) | **FIXED** |
| **SEC-02** | Cross-tenant password reset | `test_cross_tenant_isolation_for_passwords_status_stores_and_roles` (HTTP 403) | **FIXED** |
| **SEC-03** | Global status toggle (including Super Admin #1) | `test_cross_tenant_isolation_for_passwords_status_stores_and_roles` (HTTP 403) | **FIXED** |
| **SEC-04** | Mass assignment of `tenant_id`/`status` in `updateProfile` | `test_user_cannot_change_own_role_or_tenant_id` (`tenant_id` & `status` unchanged) | **FIXED** |
| **SEC-05** | Cross-tenant `stores` injection | `test_cross_tenant_isolation_for_passwords_status_stores_and_roles` (HTTP 403) | **FIXED** |
| **SEC-06** | `manage_reports` granting User CRUD | `test_staff_with_manage_reports_or_view_users_only_cannot_mutate_users` (HTTP 403 on POST/PUT/DELETE/status/password) | **FIXED** |
| **SEC-07** | Cross-tenant Role show/update/delete | `test_cross_tenant_isolation_for_passwords_status_stores_and_roles` (HTTP 403) | **FIXED** |
| **SEC-08** | Missing fine-grained CRUD backend permissions | `test_staff_with_manage_reports_or_view_users_only_cannot_mutate_users` (view-only blocked on writes, HTTP 403) | **FIXED** |
| **SEC-09** | Missing last active Super Admin & self protection | `test_last_active_super_admin_cannot_be_deleted_or_deactivated` (HTTP 403) | **FIXED** |
| **SEC-10** | Null pointer 500 on out-of-scope user IDs | `test_cross_tenant_isolation_for_passwords_status_stores_and_roles` (clean HTTP 403) | **FIXED** |
| **SEC-11** | `Role::ADMIN` exposed in tenant `GET /api/roles` | `test_store_admin_cannot_create_super_admin_or_assign_global_admin_roles` + live browser check | **FIXED** |
| **SEC-12** | State-changing `GET` status/verify endpoints | Switched to `POST`; legacy `GET /api/users/status/{id}` rejected & leaves status unchanged | **FIXED** |

---

## 3. Frontend Build & Live Browser Verification

- **React Production Build (`npm run prod`)**: **PASS** (`0` errors).
- **Live Headless Chromium Verification (`http://127.0.0.1:8000`)**:
  - `publicRegisterPageLoads`: `true` (`/app/register`)
  - `hasManageSuperAdminsButton`: `true` (`/app/admin/users`)
  - `superAdminModalShowsExisting`: `true` (`superadmin@stockmate.com` listed in modal) — [um-01-superadmin-modal.png](file:///Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/um-01-superadmin-modal.png)
  - `storeAdminCannotSeeSuperAdminRole`: `true` (`/app/user/users/create`) — [um-02-storeadmin-create-user.png](file:///Users/macminim4/.gemini/antigravity/brain/87e83e56-7d9d-40db-b3c3-38695000f7a3/scratch/um-02-storeadmin-create-user.png)
  - `pageErrors`: `[]` (`0` console/page runtime exceptions).

---

## 4. Remaining Risks / Operational Notes
- **Public Self-Registration**: Kept enabled per requirement for SaaS onboarding with `throttle:10,1`, strict field allowlisting, and server-controlled `Role::ADMIN` assignment. In production, ensure SMTP mail verification (`send_registration_mail = true`) is active to prevent spam signups.
- **Role Permission Ceiling**: A Store Admin can still create custom roles containing any seeded store permission; global Super Admin routes remain strictly gated by `role:superadmin` middleware so no store permission can ever reach `/api/sadmin/*`.
