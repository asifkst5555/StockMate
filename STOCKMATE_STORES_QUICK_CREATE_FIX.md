# StockMate — Stores Quick Create Fix

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Root Cause
- The orange **`+`** Quick Create shortcut button in `resources/pos/src/components/sidebar/asideTopSubMenuItem.js` only listed Sales, Purchase, Customer, Supplier, Product, and Expense — omitting **Store** completely and hiding the Quick Create menu entirely from Super Admins.
- Additionally, on the Store Admin's `Sidebar → Stores` page (`resources/pos/src/components/store/index.jsx`), the table's `AddStoreButton` is hidden whenever the Store Admin has reached their subscription plan's `no_of_stores` limit (the local `Default Plan` has `no_of_stores = 1`), leaving no visible Store creation entry point.
- On the backend, `StoreAPIController::store()` did not enforce role (`Role::ADMIN`) or plan `no_of_stores` limits, and `AdminRepository::storeAdmin()` hardcoded `'name' => 'My Store'` instead of accepting optional store provisioning fields.

---

## 2. Changes Implemented (Reusing Existing Architecture)

### Frontend (`resources/pos/src/`)
1. **`resources/pos/src/components/sidebar/asideTopSubMenuItem.js`**:
   - Added **Store** (`faStore` icon in StockMate Orange `#F47716`) as the **first option** in the orange `+` Quick Create dropdown:
     - **Store Admin (`ROLES.ADMIN`, non-sub-user):** Displays `1. Stores`, `2. Sales`, `3. Purchase`, `4. Customer`, `5. Supplier`, `6. Product`, `7. Expense`. Clicking **Stores** opens the existing `StoreForm` modal (`POST /api/stores`) for creating a branch store under their own tenant (showing a clear plan-limit notice if at `no_of_stores`).
     - **Super Admin (`isAdmin === true`):** Displays the orange `+` Quick Create dropdown with **Stores**, opening `CreateGlobalStoreModal`.
     - **Staff / Sub-Users (`is_sub_user === true`):** **Stores** is hidden in the dropdown and blocked by backend `403`.
2. **`resources/pos/src/components/store/CreateGlobalStoreModal.jsx`** *(New)*:
   - StockMate-themed modal for **Super Admin** supporting: Store Name, Store Code / Slug, Store Email, Store Phone, Store Address, Country, Currency, Subscription Plan, Store Admin First/Last Name, Store Admin Email, Store Admin Phone, Password, and Confirm Password.
   - Reuses the existing secure `POST /api/sadmin/admin-users` endpoint (`AdminAPIController::store` → `AdminRepository::storeAdmin`).
3. **`resources/pos/src/components/store/StoreForm.jsx`**:
   - Enhanced to display a clear warning and disable Save when a Store Admin has reached their subscription plan's `no_of_stores` limit.

### Backend (`app/`)
4. **`app/Http/Controllers/StoreAPIController.php`**:
   - Hardened `store()` to allow only `Role::ADMIN` (blocks Staff/sub-users with `403`) and enforce `Store::where('user_id', $user->id)->count() < $activeSubscription->plan->no_of_stores` (`403` when limit reached).
5. **`app/Http/Controllers/Sadmin/AdminAPIController.php`** & **`app/Repositories/AdminRepository.php`**:
   - Extended `storeAdmin()` and `defaultSettings()` backward-compatibly to accept optional `store_name`, `store_code`, `store_email`, `store_phone`, `store_address`, `country`, and `currency` inside the existing single atomic DB transaction (creating Store + Tenant + Store Admin + Subscription + tenant settings together).
6. **`tests/Feature/FinalSecurityHardeningTest.php`**:
   - Added `test_store_creation_authorization_and_plan_limit_enforcement` verifying Super Admin SaaS provisioning, Store Admin plan-limit enforcement (blocked at `1/1`, allowed at `1/2`), and Staff `403` denial.
