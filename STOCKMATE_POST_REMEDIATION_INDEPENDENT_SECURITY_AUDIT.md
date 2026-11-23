# StockMate — Post-Remediation Independent Security Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Audit Method:** Adversarial source-code review, full route/middleware table inspection, live direct HTTP/API probing against `http://127.0.0.1:8000`, PHPUnit verification (`27/27`), React production bundle secret scanning, browser verification, and post-test database integrity inspection.

---

## Overall Verdict: **CONDITIONAL — NOT YET PRODUCTION-READY (2 HIGH Findings Remaining)**

All 12 originally audited vulnerabilities (`SEC-01` through `SEC-12`) were **independently verified as FIXED** via both direct HTTP/API requests and PHPUnit tests. However, deeper adversarial testing uncovered **2 NEW HIGH-severity findings** (token/session persistence after deactivation and self-role permission escalation in `RoleAPIController`) and **1 MEDIUM transaction-boundary defect**. Production deployment should wait until `POST-01` and `POST-02` are remediated and covered by regression tests.

---

## 1. Independent Verification of All 12 Critical Areas

| # | Area Verified | Endpoint & Source File | Attack Scenario | Expected Result | Actual Result | Status | Remaining Risk |
|---|---|---|---|---|---|---|---|
| 1 | Direct API privilege escalation (`role_id` 1 & 2) | `POST /api/users` (`UserAPIController.php`, `UserAuthorizationService.php:108-140`) | Store Admin sends `role_id: 1` (`superadmin`) or `role_id: 2` (`admin`) via direct HTTP | HTTP `403` | Live HTTP `403`: `"You are not authorized to assign administrative roles."` | **PASS** | Low |
| 2 | Super Admin creation endpoint authorization | `GET/POST /api/sadmin/super-admins` (`routes/sadmin.php:45-49`, `AdminAPIController.php:66-79`) | Unauthenticated user & Store Admin call `/api/sadmin/super-admins`; Super Admin lists/creates | `401` unauth, `403` Store Admin, `200`/`201` Super Admin | Live HTTP: `401` unauth, `403` Store Admin, `200` (`count: 1`) Super Admin; PHPUnit `201` create | **PASS** | Low |
| 3 | Store Admin creation & tenant assignment | `POST /api/sadmin/admin-users` (`AdminAPIController.php:49-64`, `AdminRepository.php:63-114`) | Super Admin creates Store Admin; verify isolated `Store`, `MultiTenant`, `Subscription`, `Role::ADMIN` | `201` with `Role::ADMIN` & non-null `tenant_id` | PHPUnit verified `201`, `hasRole('admin') = true`, `hasRole('superadmin') = false`, isolated `tenant_id` | **PASS** | Medium on `updateAdmin` transaction boundary (`POST-03`) |
| 4 | Public registration role restrictions | `POST /api/register` (`routes/api.php:404`, `AuthController.php:180-235`, `RegisterRequest.php`) | Unauthenticated signup submits `role_id: 1`, victim `tenant_id`, `status: 0` | Ignores escalation fields; creates `Role::ADMIN` in new tenant; rate-limited (`throttle:10,1`) | PHPUnit verified `Role::ADMIN`, new `tenant_id`, `status: 1`, `throttle:10,1` active | **PASS** | Low (enable SMTP verification in prod) |
| 5 | Cross-tenant user access, password & status changes | `GET/DELETE /api/users/{id}`, `POST /api/change-user-password`, `POST /api/users/status/{id}` (`UserAPIController.php`, `UserAuthorizationService.php`) | Store Admin A accesses/resets/deactivates Tenant B user or Super Admin #1 | HTTP `403` (never `500`) | Live HTTP `403` on Super Admin #1; PHPUnit `403` on Tenant B user across all 4 endpoints | **PASS** | Low |
| 6 | Cross-tenant store & role manipulation | `POST /api/users` (`stores`), `GET/PUT/DELETE /api/roles/{id}` (`UserAPIController.php`, `RoleAPIController.php`) | Store Admin A assigns Tenant B `store_id` or reads/edits/deletes Tenant B custom role | HTTP `403` | PHPUnit verified `403` on cross-tenant `stores` and `GET/PUT/DELETE /api/roles/{id}` | **PASS** | Low |
| 7 | Self-role modification & `tenant_id` injection | `POST /api/users/{self}`, `POST /api/update-profile` (`UserAPIController.php:158-164`, `UserRepository.php:176-199`) | User edits own role or injects `tenant_id`, `status`, `role_id` via profile update | `403` on self-edit; profile update ignores `tenant_id`/`status`/`role_id` | PHPUnit verified `403` on self-edit and allowlist-only persistence on profile update | **PASS** | Low |
| 8 | Last active Super Admin protection | `POST /api/sadmin/super-admins/{id}/status`, `DELETE /api/sadmin/super-admins/{id}` (`AdminAPIController.php:111-180`, `UserAuthorizationService.php:205-221`) | Super Admin attempts self-delete/deactivate or inactive SA attempts to deactivate/delete sole active SA | HTTP `403` | Live HTTP `403` on SA #1; PHPUnit `403` for both self and non-self last-active SA | **PASS** | Low |
| 9 | Role deletion & permission assignment | `POST /api/roles`, `PUT /api/roles/{id}`, `DELETE /api/roles/{id}` (`RoleAPIController.php:48-95`) | Staff with `create_roles` or `edit_roles` edits **their own role** or creates a role granting permissions they do **not** possess (e.g. `manage_users`, `manage_setting`) | HTTP `403` (permission ceiling + self-role edit block) | **NO permission ceiling or self-role check exists** in `RoleAPIController::store()` or `update()`! Staff with `edit_roles` can elevate their own role to all 109 permissions! | **FAIL (HIGH — `POST-02`)** | **HIGH** |
| 10 | Alternate / legacy user & role mutation routes | `GET /api/users/status/{id}`, `GET /api/sadmin/admin-users/status/{id}` (`routes/api.php`, `routes/sadmin.php`) | Call legacy `GET` status routes; inspect `php artisan route:list` for unprotected aliases | Rejected (`405`/`422`); no unprotected user/role routes | Live HTTP `422` (`GET method is not supported`); full `route:list` confirmed no unprotected user/role mutation routes | **PASS** | Low (`GET /api/change-status/{store}` outside User Mgmt) |
| 11 | Staff with `manage_reports` attempting user CRUD | `POST/PUT/DELETE /api/users`, `POST /api/users/status/{id}`, `POST /api/change-user-password` (`routes/api.php:188-203`) | Staff with only `manage_reports` (or `view_users`) attempts user mutations | `200` on `GET /api/users`; `403` on all write endpoints | PHPUnit verified `200` on `GET` and `403` on all 5 write endpoints | **PASS** | Low |
| 12 | Auth / session behavior after deactivation or role change | `UserAPIController::statusUpdate`, `AdminAPIController::statusUpdate`, Sanctum middleware (`app/Http/Middleware/`) | Deactivate an active user (`status = 0`) who already holds a Sanctum Bearer token and call protected API endpoints | Existing tokens revoked immediately (`$user->tokens()->delete()`) and active-status middleware rejects `status = 0` with `401`/`403` | **Tokens are NOT revoked on deactivation** (`DEACTIVATION_TOKEN_CHECK: before=1 after=1`), and no middleware blocks `status = 0` on existing Bearer tokens! | **FAIL (HIGH — `POST-01`)** | **HIGH** |

---

## 2. Detailed New Findings Discovered

### `POST-01` — HIGH: Deactivated Users Retain Active Sanctum Bearer Tokens & API Access
- **Files:** `app/Http/Controllers/API/UserAPIController.php:318-334`, `app/Http/Controllers/Sadmin/AdminAPIController.php:163-179`, `app/Http/Kernel.php`
- **Attack Scenario:** A Store Admin deactivates a terminated Cashier (`POST /api/users/status/{id}` → `status = 0`), or a Super Admin suspends a Store Admin (`POST /api/sadmin/admin-users/status/{id}` → `status = 0`). The suspended user continues sending requests with their previously issued `Authorization: Bearer <token>`.
- **Expected Result:** All active Sanctum tokens for the user are immediately revoked upon deactivation (`$user->tokens()->delete()`), AND an authenticated middleware enforces `$request->user()->status == 1` on every protected API call.
- **Actual Result:** Empirically verified via rollback-wrapped probe: `DEACTIVATION_TOKEN_CHECK: before=1 after=1`. Existing tokens remain valid and accepted by `auth:sanctum`.
- **Recommended Fix:**
  1. Revoke all tokens (`$user->tokens()->delete()`) whenever `status` transitions to `0` in `UserAPIController::statusUpdate()` and `AdminAPIController::statusUpdate()` (and upon password reset / role change if desired).
  2. Add an `EnsureUserIsActive` middleware to authenticated API route groups returning HTTP `403`/`401` if `!Auth::user()->status`.

### `POST-02` — HIGH: Privilege Escalation via `POST /api/roles` & `PUT /api/roles/{role}`
- **Files:** `app/Http/Controllers/API/RoleAPIController.php:48-78`, `app/Services/UserAuthorizationService.php`
- **Attack Scenario:** A Store Admin grants a Manager custom role `edit_roles` (or `create_roles`) without granting `manage_users` or `manage_setting`. The Manager calls `PUT /api/roles/{their_own_role_id}` passing all 109 permissions, immediately escalating their own account to full Store Admin privileges.
- **Expected Result:**
  1. Non-admin users cannot edit their **own** currently assigned role (`403`).
  2. When creating or updating a role, non-Super-Admin / non-Store-Admin users cannot attach any permission they do not themselves hold (`403`).
  3. Reserved names (`superadmin`, `admin`) should be explicitly rejected with `403`/`422` before hitting Spatie's exception.
- **Actual Result:** `RoleAPIController::store()` and `update()` accept any permission array without checking the caller's permission ceiling or self-role modification.

### `POST-03` — MEDIUM: Split Transaction Boundary in `AdminRepository::updateAdmin()`
- **File:** `app/Repositories/AdminRepository.php:122-204`
- **Issue:** `DB::commit()` executes at line 132 before subscription updates, store disabling, `UserStore` deletion, and `tenant_id` reassignments (lines 134–202). If an exception occurs after line 132, the user update is already committed and `DB::rollBack()` in `catch` cannot revert the partial state.
- **Recommended Fix:** Move `DB::commit()` to line 203 (immediately before `return $user;`).

### `POST-04` — LOW: Legacy `GET` Store Status Mutation Route
- **File:** `routes/api.php:84` (`GET /api/change-status/{store}`)
- **Note:** Outside User Management, but mutates store status over HTTP `GET`. Recommend migrating to `POST` in a future hardening pass.

---

## 3. Additional Verification Results

- **Full PHPUnit Suite (`php artisan test`)**: **27 passed, 0 failed (`1.05s`)**.
- **React Production Bundle (`public/js/app.js`) Secret Scan**: **PASS** — Zero credentials, passwords, `.env` keys, or API tokens exposed (`stripe_secret` appears strictly as a React form property name).
- **Authorization Service Fail-Open Review (`UserAuthorizationService.php`)**: **PASS** — All `assert*` methods default-deny (`throw new AuthorizationException`) when store lists are empty, roles are missing/global, or `tenant_id` is null/mismatched.
- **Post-Test Database Integrity**: **PASS** — Real accounts completely intact:
  - User #1: `superadmin@stockmate.com` (`tenant_id: null`, `status: 1`, role `superadmin`)
  - User #2: `admin@stockmate.com` (`tenant_id: f58e82d5-2dd0-460a-be33-2a6b8cda9ad1`, `status: 1`, role `admin`, Store #1 `My Store`)
- **Browser Verification (Super Admin & Store Admin)**: **PASS** — `0` JS page errors, Super Admin management modal works, Store Admin `Create User` form hides administrative roles.
