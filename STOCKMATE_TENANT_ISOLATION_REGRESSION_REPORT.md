# StockMate — Tenant Isolation Regression Report

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Cross-Tenant Isolation Test Matrix (Verified via PHPUnit)

Every cross-tenant boundary identified in `STOCKMATE_TENANT_USER_ISOLATION_AUDIT.md` is now enforced server-side by `App\Services\UserAuthorizationService` and verified by automated regression tests:

| Boundary / Endpoint | Attack Tested | Expected Result | Actual Result |
|---|---|---|---|
| `POST /api/users` (`stores` parameter) | Tenant A Store Admin submits Tenant B's `store_id` | HTTP `403` | **PASS (`403`)** |
| `POST /api/users` (`role_id` parameter) | Tenant A Store Admin submits `role_id = 1` (`superadmin`) or `role_id = 2` (`admin`) | HTTP `403` | **PASS (`403`)** |
| `GET /api/users/{id}` | Tenant A Store Admin requests Tenant B's staff user ID | HTTP `403` (no 500 crash) | **PASS (`403`)** |
| `DELETE /api/users/{id}` | Tenant A Store Admin attempts to delete Tenant B's staff user | HTTP `403` | **PASS (`403`)** |
| `POST /api/change-user-password` | Tenant A Store Admin attempts to reset Tenant B user's password | HTTP `403` | **PASS (`403`)** |
| `POST /api/users/status/{id}` | Tenant A Store Admin attempts to deactivate Tenant B user or Super Admin #1 | HTTP `403` | **PASS (`403`)** |
| `GET /api/users/status/{id}` | Legacy HTTP `GET` state mutation attempt | Rejected (`405`/`422`), status unchanged | **PASS (Rejected, status intact)** |
| `POST /api/update-profile` | Staff user submits `tenant_id` of Tenant B, `status: 0`, `role_id: 1` | Ignored — `tenant_id`, `status`, and role unchanged | **PASS** |
| `POST /api/register` | Public user submits `role_id: 1` and Victim's `tenant_id` | Ignored — assigned `Role::ADMIN` with fresh isolated tenant | **PASS** |
| `GET /api/roles/{id}` | Tenant A Store Admin reads Tenant B's custom role | HTTP `403` | **PASS (`403`)** |
| `PUT /api/roles/{id}` | Tenant A Store Admin updates Tenant B's custom role | HTTP `403` | **PASS (`403`)** |
| `DELETE /api/roles/{id}` | Tenant A Store Admin deletes Tenant B's custom role | HTTP `403` | **PASS (`403`)** |
| `POST /api/change-store/{id}` & `DELETE /api/stores/{id}` | Existing `SecurityAndPosAuditTest` cross-tenant store isolation | HTTP `403` | **PASS (`403`)** |
| `GET /api/private-files/{path}` | Existing `SecurityAndPosAuditTest` cross-tenant & traversal isolation | HTTP `403` | **PASS (`403`)** |

---

## 2. Database Integrity Post-Verification
- `users`: ID `1` (`superadmin@stockmate.com`, `tenant_id = NULL`, `status = 1`) and ID `2` (`admin@stockmate.com`, `tenant_id = f58e82d5-2dd0-460a-be33-2a6b8cda9ad1`, `status = 1`) remain completely intact.
- Zero test rows persisted (all tests run cleanly inside `DatabaseTransactions`).
