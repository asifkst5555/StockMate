# StockMate — Store Limit Management Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Existing Architecture Findings

1. **Plan-Level Store Limit (`plans.no_of_stores`)**:
   - `App\Models\Plan` already includes `no_of_stores` (validated previously as `required|numeric|min:1`), surfaced in `PlanForm.jsx` and `EditPlan.jsx`.
   - Unlimited stores (`-1`) was not previously supported in `Plan::$rules` or `PlanForm.jsx`.
2. **Per-Customer / Per-Store-Admin Override**:
   - No per-customer override column existed on `users`, so Super Admin could only change a customer's store allowance by switching their entire subscription plan.
3. **Scattered Limit Checks**:
   - `StoreAPIController::store()`, `StoreAPIController::changeStatus()`, and `UserAPIController::config()` each queried `Subscription` and `Plan::no_of_stores` independently instead of calling a single authoritative service.
4. **Store Admin Visibility**:
   - `resources/pos/src/components/store/index.jsx` hid `<AddStoreButton />` when at limit without displaying a `Stores Used: X / Y` counter or clear explanation.
5. **Super Admin Visibility**:
   - `resources/pos/src/components/admin/users/AdminUserDetail.jsx` displayed basic user profile fields only, with no Store Creation Limit overview or override controls.
6. **Bug Discovered During Audit**:
   - `UserAPIController::config()` used `file_get_contents('../composer.json')`, which threw an unhandled error under CLI/PHPUnit working directories; replaced with `base_path('composer.json')`.
