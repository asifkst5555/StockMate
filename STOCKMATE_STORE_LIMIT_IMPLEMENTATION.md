# StockMate — Store Limit Management Implementation

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Database & Backend Architecture

1. **Non-Destructive Migration**:  
   - `database/migrations/2026_11_23_133500_add_store_limit_override_to_users_table.php`  
   - Added nullable integer `users.store_limit_override` (`NULL` = use plan limit, `-1` = Unlimited, `>= 1` = custom max stores).
   - **Security:** `store_limit_override` is intentionally **excluded** from `User::$fillable`, preventing mass assignment from any user-facing endpoint.
2. **Centralized Authoritative Service (`app/Services/StoreLimitService.php`)**:
   - `getStoreLimitSummary(User $user)` computes:
     - `plan_name`, `plan_store_limit`, `store_limit_override`, `effective_store_limit`, `is_unlimited`, `active_store_count`, `total_store_count`, `remaining_store_slots`, and `can_create_store`.
   - Precedence: `store_limit_override` (when non-null) overrides `plan.no_of_stores`.
   - Strictly counts `Store::where('user_id', $user->id)` so global or other-tenant stores are never miscounted.
3. **Plan Unlimited Support (`app/Models/Plan.php`)**:
   - Updated `Plan::$rules['no_of_stores']` to `'required|integer|min:-1|not_in:0'`, enabling both positive numeric limits (Starter/Business/Professional/Enterprise) and `-1` (Unlimited).
4. **Super Admin Override Endpoint (`routes/sadmin.php`, `app/Http/Controllers/Sadmin/AdminAPIController.php`)**:
   - `POST /api/sadmin/admin-users/{admin_user}/store-limit` (protected by `auth:sanctum`, `role:superadmin`, `verified`, `active_user`).
   - Validates `store_limit_override` as `nullable|integer|min:-1|max:10000|not_in:0` and updates via `$user->forceFill(['store_limit_override' => $override])->save()`.
5. **Unified Server-Side Enforcement**:
   - `StoreAPIController::store()`, `StoreAPIController::changeStatus()`, `UserAPIController::config()`, and `User::prepareAttributes()` all delegate to `StoreLimitService`.

---

## 2. Frontend UI Updates

1. **Super Admin Plan Form (`resources/pos/src/components/admin/plans/PlanForm.jsx`)**:
   - Added **Unlimited Stores** checkbox alongside `Number of Stores`, toggling `no_of_stores` between `-1` (`Unlimited`) and numeric values (`>= 1`).
2. **Super Admin User Detail (`resources/pos/src/components/admin/users/AdminUserDetail.jsx`)**:
   - Added **Store Creation Limit** card displaying: Subscription Plan, Plan Store Limit, Custom Store Limit Override, Current Active Store Count, and Remaining Store Slots.
   - Added Super Admin action controls to **Save Custom Limit**, **Enable Unlimited Stores**, or **Remove Override (Use Plan Limit)**.
3. **Store Admin Stores Page (`resources/pos/src/components/store/index.jsx`) & Quick Create (`asideTopSubMenuItem.js`)**:
   - Displays **`Stores Used: X / Y`** (or **`Stores Used: X / Unlimited`**) badge at the top of the Stores page.
   - Shows a clear limit-reached alert when `total >= effective_store_limit` (unless unlimited) and dynamically respects `is_unlimited_stores` in both `<AddStoreButton />` and the Quick Create dropdown.
