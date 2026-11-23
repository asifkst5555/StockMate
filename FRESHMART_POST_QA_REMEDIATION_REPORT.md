# FreshMart — Post-QA Critical Findings Remediation Report

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Environment:** Local Docker (`http://127.0.0.1:8000`) — Local Only (no DB reset, no reseed, no deleted business records, no commit/push/deploy)  
**Final Verification:** PHPUnit **`39 passed, 0 failed` (`1.59s`)** | React Production Build (`npm run prod`) **`0` errors** | Live API & Browser (`1440×900`, `390×844`) **PASS**

---

## Executive Summary

All five post-QA findings (`QA-FIND-01` through `QA-FIND-05`) have been remediated in priority order, verified with automated PHPUnit regression tests in [FreshMartPostQaRemediationTest.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/tests/Feature/FreshMartPostQaRemediationTest.php), validated against the live FreshMart 3-branch demo environment, and visually confirmed via recaptured `1440×900` and `390×844` screenshots. Zero schema changes and zero destructive data operations were required.

---

## Phase 1 — `QA-FIND-02` (HIGH): Tenant-Scoped Transfer Validation

- **Original Issue:** `POST /api/transfers` accepted a `to_warehouse_id` belonging to a different store/tenant (`FreshMart Bheramara` warehouse `340` submitted from `FreshMart Central`), returning `201`, persisting a cross-tenant `Transfer` and `ManageStock` row, and causing a `null` warehouse crash (`Cannot read properties of null (reading 'name')`) in the Dashboard Stock Alerts and Transfers UI.
- **Root Cause:** `Transfer::$rules` used unscoped `'exists:warehouses,id'` for both `from_warehouse_id` and `to_warehouse_id`, and `TransferRepository::storeTransfer()` / `updateTransfer()` did not verify tenant ownership of the warehouses before writing.
- **Files Changed:**
  - [CreateTransferRequest.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Http/Requests/CreateTransferRequest.php)
  - [UpdateTransferRequest.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Http/Requests/UpdateTransferRequest.php)
  - [TransferRepository.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Repositories/TransferRepository.php)
- **Fix Implemented:**
  - Scoped `from_warehouse_id` and `to_warehouse_id` in `CreateTransferRequest` and `UpdateTransferRequest` with `Rule::exists('warehouses', 'id')->where(fn ($q) => $q->where('tenant_id', Auth::user()?->tenant_id))` plus `'different:from_warehouse_id'`.
  - Added defense-in-depth tenant-scoped `Warehouse::find()` verification inside `TransferRepository::storeTransfer()` and `updateTransfer()` before any transfer or stock mutation.
- **Tests Added:** `FreshMartPostQaRemediationTest::test_phase1_cross_tenant_destination_warehouse_transfer_is_rejected`
- **Before / After Results:**
  - *Before:* `POST /api/transfers` (`339 → 340`) returned `201` and created a cross-tenant stock row.
  - *After:* `POST /api/transfers` (`339 → 340`) returns **`422`** (`"The selected to warehouse id is invalid."`), `0` transfers persisted, source stock unchanged, and `0` cross-tenant `manage_stocks` rows created.
- **Remaining Limitations:** Cross-branch inter-store stock transfers remain an intentionally unbuilt roadmap feature (documented in [FRESHMART_DEMO_FEATURE_GAP_REPORT.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_DEMO_FEATURE_GAP_REPORT.md)); transfers strictly operate between warehouses within the same branch.

---

## Phase 2 — `QA-FIND-01` (MEDIUM): Expense Permissions Enforcement

- **Original Issue:** `inventory@freshmart.demo` (which holds `manage_reports` but does **not** hold `manage_expenses`) was able to create expenses via `POST /api/expenses` (`201`).
- **Root Cause:** In [routes/api.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/routes/api.php), the entire `Route::resource('expenses', ExpenseAPIController::class)` group was wrapped in `permission:manage_expenses|manage_reports`.
- **Files Changed:**
  - [routes/api.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/routes/api.php)
- **Fix Implemented:** Split the `expenses` routes so `POST`, `PUT`, `PATCH`, and `DELETE` (`->except(['index', 'show'])`) strictly require `permission:manage_expenses`, while read-only `GET /api/expenses` and `GET /api/expenses/{expense}` remain accessible to `permission:manage_expenses|manage_reports` for reporting.
- **Tests Added:** `FreshMartPostQaRemediationTest::test_phase2_inventory_officer_cannot_mutate_expenses_while_owner_can`
- **Before / After Results:**
  - *Before:* Inventory Officer `POST /api/expenses` returned `201`.
  - *After:* Inventory Officer `GET /api/expenses` returns `200`, while `POST /api/expenses` and `DELETE /api/expenses/{id}` return **`403`** (`"User dose not have the right permission."`). Authorized Owner continues to create expenses (`201`).
- **Remaining Limitations:** None for expense mutations.

---

## Phase 3 — `QA-FIND-03` (MEDIUM): Complete Stock Movement Ledger

- **Original Issue:** Stock Adjustments (`AdjustmentRepository`), Transfer source deductions (`TransferRepository`), and Sales Returns (`SaleReturnRepository`) updated `manage_stocks.quantity` accurately, but did not write corresponding rows to `stock_movements`, leaving gaps in the stock movement audit trail.
- **Root Cause:** Those three repositories mutated `ManageStock` directly instead of calling `recordStockMovement()` (and `TransferRepository` passed default `'manual'` type with `null` reference to `manageStock()`).
- **Files Changed:**
  - [AdjustmentRepository.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Repositories/AdjustmentRepository.php)
  - [TransferRepository.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Repositories/TransferRepository.php)
  - [SaleReturnRepository.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Repositories/SaleReturnRepository.php)
- **Fix Implemented:**
  - Added atomic `lockForUpdate()` + `recordStockMovement()` inside the existing DB transactions for:
    - **Stock Adjustments:** `'adjustment_add'` (`+$qty`) and `'adjustment_sub'` (`-$qty`) linked to `Adjustment::class`.
    - **Warehouse Transfers:** `'transfer_out'` (`-$qty` on source warehouse) and `'transfer_in'` (`+$qty` via `manageStock()` on destination warehouse) linked to `Transfer::class`.
    - **Sales Returns:** `'sale_return'` (`+$qty`) linked to `SaleReturn::class`.
- **Tests Added:** `FreshMartPostQaRemediationTest::test_phase3_stock_movements_logged_for_adjustment_transfer_and_sale_return`
- **Before / After Results:**
  - *Before:* Only Purchases, Sales, and Transfer destination increments (`type: manual`) were logged to `stock_movements`.
  - *After:* Adjustments (`adjustment_sub` / `adjustment_add`), Transfers (`transfer_out` + `transfer_in`), and Sales Returns (`sale_return`) each log exactly 1 movement row inside the same DB transaction, and `opening_stock + SUM(quantity_change)` equals `manage_stocks.quantity` for both source (`24.0`) and destination (`15.0`) warehouses.
- **Remaining Limitations:** Historical transactions created prior to this remediation were preserved untouched (no backfill/mutation of existing records).

---

## Phase 4 — `QA-FIND-04` & `QA-FIND-05` (LOW): UI & Unit Display Corrections

### `QA-FIND-04`: Reports Sub-Navigation Horizontal Overflow at `1440px`
- **Original Issue:** On Reports pages (`Stock Reports`, `Profit & Loss`, `Low-Stock Report`) at `1440×900`, the horizontal sub-navbar overflowed (`scrollWidth: 1619px > innerWidth: 1440px`), clipping the right side of the user profile name.
- **Root Cause:** `useResponsiveLimit()` returned `limit: 5, lessThanLimit: 4` for `1400px ≤ width < 1600px`, rendering 5 wide report links alongside the sidebar, clock, POS button, and profile menu.
- **Files Changed:**
  - [useResponsiveLimit.js](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/utils/useResponsiveLimit.js)
- **Fix Implemented:** Tightened responsive breakpoints (`width < 1560` now shows `3` visible report links + `More ▾` dropdown; `width < 1760` shows `4`), preserving the StockMate Orange theme and all report links inside `More ▾`.
- **Before / After Results:**
  - *Before:* `scrollWidth = 1619px` on Stock Report, Profit & Loss, and Low-Stock Report at `1440×900`.
  - *After:* `scrollWidth = 1440px` (Stock Report) and `1435px` (Profit & Loss, Low-Stock Report) — **`0px` horizontal overflow**; mobile `390×844` remains `390px === 390px` (`0px` overflow).

### `QA-FIND-05`: `PRODUCT UNIT` Showing `N/A` on Seeded Products
- **Original Issue:** On `/app/user/products`, all 162 seeded FreshMart products displayed `N/A` in the `PRODUCT UNIT` column, whereas newly API-created products displayed `kilogram`.
- **Root Cause:** `MainProduct::getProductUnitName()` only queried `BaseUnit::whereId($this->product_unit)->first()`, returning `''` whenever `main_products.product_unit` stored a `units.id` (e.g., `163` = `1 Kilogram`, whose `base_unit` is `121` = `kilogram`).
- **Files Changed:**
  - [MainProduct.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Models/MainProduct.php)
- **Fix Implemented:** Added a non-destructive fallback in `MainProduct::getProductUnitName()` that resolves `Unit::whereId($this->product_unit)` → its linked `BaseUnit` (or the `Unit` itself if no `base_unit` is set), without modifying any database rows.
- **Tests Added:** `FreshMartPostQaRemediationTest::test_phase4_main_product_resolves_unit_id_to_base_unit_name`
- **Before / After Results:**
  - *Before:* `GET /api/main-products/312` returned `product_unit: ""` and UI rendered `N/A` across all seeded rows.
  - *After:* `GET /api/main-products/312` returns `product_unit: { id: 121, name: "kilogram" }` and `/app/user/products` displays green `kilogram` badges with `0` `N/A` occurrences (`hasNA: false` verified in [03-products-list-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/03-products-list-1440x900.png)).
- **Remaining Limitations:** None.
