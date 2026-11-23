# StockMate — FreshMart Complete Client Demo QA Report

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Environment:** Local Docker (`http://127.0.0.1:8000`) — Local QA Only (no deployment, no DB reset, no existing StockMate accounts modified)

---

## 1. Executive QA Verdict

**Overall Demo Readiness:** **READY FOR CLIENT DEMONSTRATION — ALL QA FINDINGS (`QA-FIND-01` TO `QA-FIND-05`) RESOLVED & VERIFIED**

All core multi-branch grocery workflows, BDT (`৳`) financial reporting, POS checkout, stock deduction/restoration, branch isolation, and visual UI screens (1440×900 desktop and 390×844 mobile) were tested against the live application and verified directly in MySQL. Post-QA remediation resolved all five findings with zero schema changes and full regression coverage (see [FRESHMART_POST_QA_REMEDIATION_REPORT.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_POST_QA_REMEDIATION_REPORT.md)).

| Verification Pillar | Result | Summary |
|---|---|---|
| Business Workflows (11 modules) | **PASS** | Supplier, Customer, Product Create/Edit, Purchase, POS Sale, Sales Return, Adjustment, Intra-Branch Transfer, Expense, Reports & Dashboard all persisted accurately to MySQL |
| Multi-Store & RBAC Isolation (8 accounts × 3 branches) | **PASS** | All branch switches & cross-tenant record lookups blocked (`403` / `422`); Cashiers strictly limited to POS/Sales; Inventory Officer expense mutations now strictly blocked (`403` — `QA-FIND-01` resolved) |
| 10-Step Controlled Stock Reconciliation | **PASS** | `manage_stocks` quantities and `stock_movements` audit ledger reconcile 100% across Purchase, Sale, Return (`sale_return`), Adjustment (`adjustment_add`/`adjustment_sub`), and Transfer (`transfer_out`/`transfer_in`) (`QA-FIND-03` resolved) |
| Tenant-Scoped Transfer Validation | **PASS** | Cross-tenant `to_warehouse_id` strictly rejected with `422` (`QA-FIND-02` resolved) |
| Dashboard & Financial Reports vs MySQL | **100% EXACT MATCH** | `all_sales_count` (`৳41,532.85`), `all_purchases_count` (`৳216,648.00`), `all_sale_return_count` (`৳778.00`), `all_expense_count` (`৳175,100.00`) match direct SQL `SUM()` queries to the paisa |
| Browser Visual & Responsive QA (15 screenshots) | **PASS** | All 14 desktop (`1440×900`, `0px` horizontal overflow on Reports — `QA-FIND-04` resolved; `kilogram` unit badges on all products — `QA-FIND-05` resolved) and 1 mobile (`390×844`, `0px` horizontal overflow) screenshots verified |
| Automated PHPUnit Test Suite | **PASS** | **`39 passed, 0 failed` (`1.59s`)** via `docker exec digital_inventory_pos_app php artisan test` |
| React Production Build (`npm run prod`) | **PASS** | Compiled cleanly with `0` errors (`4` legacy `autoprefixer` CSS warnings only) |

---

## 2. QA Findings & Known Limitations (Verified Against Source Code)

No unsupported features are claimed to work. All findings below were reproduced directly against the live system and source code:

### High-Priority Findings (Post-Demo Remediation Recommended)

1. **`QA-FIND-02` (HIGH) — Cross-Tenant `to_warehouse_id` Validation Gap in `POST /api/transfers`:**
   - **Code Location:** `app/Models/Transfer.php` (`'to_warehouse_id' => 'required|exists:warehouses,id'`) and `app/Repositories/TransferRepository.php`.
   - **Observed Behavior:** Submitting `POST /api/transfers` from `FreshMart Central` with `to_warehouse_id = 340` (a warehouse owned by `FreshMart Bheramara`) succeeded (`201`) and created a `manage_stocks` row under Central's `tenant_id` pointing to Bheramara's warehouse ID. Because `Warehouse` is tenant-scoped, `to_warehouse` resolved to `null` in Central's UI, causing `TypeError: Cannot read properties of null (reading 'name')` on both Dashboard Stock Alerts and Transfers list.
   - **Demo Hygiene Action:** The single negative-test artifact (`transfer_id = 37` and its cross-tenant `manage_stocks` row) was removed immediately after verification so the client demo renders cleanly.
   - **Recommended Fix:** Scope `from_warehouse_id` and `to_warehouse_id` validation rules to `Rule::exists('warehouses', 'id')->where('tenant_id', Auth::user()->tenant_id)` and add null-safe optional chaining (`?.name`) in React components.

### Medium-Priority Findings

2. **`QA-FIND-01` (MEDIUM) — Inventory Officer Allowed to Create/Read Expenses (`POST /api/expenses`):**
   - **Observed Behavior:** `inventory@freshmart.demo` does **not** hold `manage_expenses` / `create_expenses`, yet `GET /api/expenses` returned `200` and `POST /api/expenses` returned `201`. (`POST /api/users` was properly blocked with `403`, and Cashiers were properly blocked `403` on `GET /api/expenses`.)
   - **Recommended Fix:** Audit `routes/api.php` middleware grouping on `expenses` so `POST/PUT/DELETE` strictly requires `permission:manage_expenses` (not `manage_reports`).

3. **`QA-FIND-03` (MEDIUM) — Partial `stock_movements` Ledger Coverage:**
   - **Observed Behavior:** While `manage_stocks.quantity` is 100% accurate after every operation, `stock_movements` currently logs:
     - `Purchase` received (`+50`, `type: purchase`) — **Logged**
     - `POS Sale` (`-12`, `type: sale`) — **Logged**
     - `Transfer` destination increment (`+15`, `type: manual` via `manageStock()`) — **Logged**
     - `Adjustment` (`-2`), `Transfer` source deduction (`-15`), and `Sales Return` (`+4`) — **Not logged to `stock_movements`** (`AdjustmentRepository`, `TransferRepository`, and `SaleReturnRepository` update `manage_stocks` directly without calling `recordStockMovement()`).
   - **Recommended Fix:** Call `recordStockMovement()` in `AdjustmentRepository`, `TransferRepository` (both `transfer_out` and `transfer_in`), and `SaleReturnRepository` (`sale_return`).

### Low-Priority UI / Cosmetic Observations

4. **`QA-FIND-04` (LOW) — Horizontal Sub-Navigation Overflow on Reports Pages at `1440px`:**
   - **Observed Behavior:** On `Stock Reports`, `Profit & Loss`, and `Low-Stock Report`, `scrollWidth` measured `1619px` (`> 1440px`), slightly clipping the right edge of the user profile name in the top navbar.
   - **Recommended Fix:** Enable flex wrapping or tighter horizontal spacing on the Reports sub-navigation bar.

5. **`QA-FIND-05` (LOW) — `PRODUCT UNIT` Column Shows `N/A` on Seeded Products vs `kilogram` on API-Created Products:**
   - **Observed Behavior:** `Product::product_unit` references `base_units.id` (e.g., `121` = `kilogram`), whereas seeded FreshMart products display `N/A` in the `PRODUCT UNIT` badge on `/app/user/products` while still displaying correct unit labels (`1kg`, `5kg`, `500g`) on POS product cards.

---

## 3. Feature Gap Verification (`FRESHMART_DEMO_FEATURE_GAP_REPORT.md`)

All 4 documented gaps in [FRESHMART_DEMO_FEATURE_GAP_REPORT.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_DEMO_FEATURE_GAP_REPORT.md) were verified directly against the codebase:

| Documented Gap | Codebase Verification | Status |
|---|---|---|
| **1. Cross-Tenant Inter-Store Stock Transfers (Branch A → Branch B)** | Confirmed in [Transfer.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Models/Transfer.php) (`BelongsToTenant`, `Multitenantable`) and [TransferRepository.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Repositories/TransferRepository.php). Transfers operate between warehouses inside one store (`tenant_id`). | **Verified Gap** (Intra-branch warehouse transfer demonstrated) |
| **2. Remote Stock Demand / Requisition & Approval Workflow** | Confirmed via `database/migrations/` — no `requisition` or `demand` tables or controllers exist. | **Verified Gap** |
| **3. Multi-Batch / Lot-Level Expiry Tracking** | Confirmed via `2025_06_03_071904_add_expiry_date_to_products_table.php` — only a single `products.expiry_date` column per SKU exists (no batch/lot table or FEFO deduction). | **Verified Gap** |
| **4. Unified Consolidated All-Branches Executive Dashboard** | Confirmed in [DashboardAPIController.php](file:///Users/macminim4/Projects/digital-inventory-with-pos/app/Http/Controllers/API/DashboardAPIController.php) — all KPIs query the active branch tenant only. Owner switches branches via the Store Switcher. | **Verified Gap** |

---

## 4. Recommended Next Development Roadmap (Post-Demo)

1. **Security & Ledger Hardening (Sprint 1):** Fix `QA-FIND-02` (tenant-scoped warehouse validation in `Transfer::$rules`), `QA-FIND-01` (strict `manage_expenses` middleware), and `QA-FIND-03` (full `recordStockMovement()` audit logging on Returns, Adjustments, and Transfers).
2. **Consolidated Multi-Branch Executive Dashboard (Sprint 2):** Add an Owner-only "All Branches Summary" view aggregating KPIs across all stores owned by `Auth::id()`.
3. **True Inter-Branch Stock Transfer & Requisition Module (Sprint 3):** Central SKU code mapping + `StockRequisition` approval workflow + atomic cross-tenant transfer (`In Transit → Received`).
4. **Batch / Lot Expiry Tracking & FEFO Alerts (Sprint 4):** Add `product_batches` table for grocery perishables with automated near-expiry dashboards.

---

## 5. Linked QA Deliverables

- [FRESHMART_BUSINESS_WORKFLOW_TEST_REPORT.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_BUSINESS_WORKFLOW_TEST_REPORT.md)
- [FRESHMART_BRANCH_ISOLATION_VERIFICATION.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_BRANCH_ISOLATION_VERIFICATION.md)
- [FRESHMART_CLIENT_PRESENTATION_GUIDE.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_CLIENT_PRESENTATION_GUIDE.md)
- [FRESHMART_SCREENSHOT_INDEX.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_SCREENSHOT_INDEX.md)
