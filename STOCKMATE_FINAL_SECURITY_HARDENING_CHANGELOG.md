# StockMate — Final Security Hardening Changelog (`POST-01` to `POST-04`)

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Reference Audit:** `STOCKMATE_POST_REMEDIATION_INDEPENDENT_SECURITY_AUDIT.md`

---

## 1. Files Created

1. **`app/Http/Middleware/EnsureUserIsActive.php`** (`POST-01`)  
   Middleware registered as `active_user` that rejects authenticated requests from deactivated users (`status != 1`) with HTTP `401`, revokes any active Sanctum `PersonalAccessToken`, and invalidates active web sessions.
2. **`tests/Feature/FinalSecurityHardeningTest.php`** (`POST-01` to `POST-04`)  
   Dedicated PHPUnit regression suite (using `DatabaseTransactions`) verifying token revocation on deactivation, role self-escalation & permission ceiling enforcement, atomic rollback in `AdminRepository::updateAdmin()`, and `POST`-only store status changes with ownership checks.

---

## 2. Files Modified

### `POST-01` — Deactivated User Token Revocation & Active-Status Enforcement
- **`app/Http/Kernel.php`**: Registered `'active_user' => \App\Http\Middleware\EnsureUserIsActive::class`.
- **`routes/api.php` & `routes/sadmin.php`**: Added `active_user` middleware to all authenticated API and Super Admin route groups.
- **`app/Services/UserAuthorizationService.php`**: Added `revokeUserSessionsAndTokens(User $user)` to delete all Sanctum personal access tokens (`$user->tokens()->delete()`) and any database session records for that user.
- **`app/Http/Controllers/API/UserAPIController.php`**: Calls `revokeUserSessionsAndTokens($user)` whenever a tenant user is deactivated (`status == 0`).
- **`app/Http/Controllers/Sadmin/AdminAPIController.php`**: Calls `revokeUserSessionsAndTokens($user)` whenever a Store Admin or Super Admin is deactivated (`status == 0`).

### `POST-02` — Role Self-Escalation & Permission Ceiling
- **`app/Services/UserAuthorizationService.php`**: Added `assertCanManageRolePermissions(User $actor, array $permissions, ?Role $targetRole = null, ?string $newName = null)`:
  - Rejects reserved system role names (`superadmin`, `admin`) with HTTP `403`.
  - Blocks any non-Super Admin from editing their **own** currently assigned role (`403`).
  - Resolves submitted permission IDs and names against `permissions`, rejecting unknown/manipulated values (`403`).
  - Enforces a strict permission delegation ceiling: non-Super Admins cannot create or update a role with any permission they do not themselves possess (`403`).
- **`app/Http/Controllers/API/RoleAPIController.php`**: Wired `assertCanManageRolePermissions` into `store()` and `update()`, and switched `show()` and `update()` to stateless `Role::withoutGlobalScope('tenant')->findOrFail($role)` lookups.

### `POST-03` — Atomic Transaction in `AdminRepository::updateAdmin()`
- **`app/Repositories/AdminRepository.php`**: Removed premature `DB::commit()` at line 132 and moved `DB::commit()` to the end of `updateAdmin()` so user attributes, media, subscription changes, store disabling, `UserStore` cleanup, and `tenant_id` reassignments commit or roll back atomically inside one transaction.

### `POST-04` — Mutating `GET /api/change-status/{store}` Converted to `POST`
- **`routes/api.php`**: Changed `GET /api/change-status/{store}` to `POST /api/change-status/{store}` (preserving `auth:sanctum`, `verified`, `active_user`, `check_subscription`, and `authorizeStoreAccess($store, true)`).
- **`resources/pos/src/store/action/storeAction.js`**: Updated `changeStoreStatus` from `apiConfig.get(...)` to `apiConfig.post(...)`.
- Recompiled React production bundle (`npm run prod`).
