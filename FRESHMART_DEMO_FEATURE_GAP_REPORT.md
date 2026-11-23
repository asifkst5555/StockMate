# FreshMart Grocery — Supported Workflows & Feature Gap Report

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Fully Supported & Demonstrated Workflows
- **Multi-Branch Ownership under One Account:** Single Owner (`owner@freshmart.demo`) with `store_limit_override = 3` managing 3 isolated branches (`FreshMart Central`, `FreshMart Bheramara`, `FreshMart Mirpur`) via Store Switcher.
- **Role-Based & Branch-Scoped Staff Access:** Separate Branch Managers, multi-branch Inventory Officer, and branch-locked Cashiers enforced server-side via `user_stores` and Spatie Permissions.
- **Grocery Catalog & BDT Currency:** 18 categories, 162 realistic Bangladesh SKUs per branch, units, brands, barcodes, stock alerts, `expiry_date`, and BDT (`৳`) formatting.
- **Reconciled Inventory Ledger:** `manage_stocks` and `stock_movements` reconciled across opening stock, received purchases, POS sales, sales returns, warehouse transfers, and damage/audit adjustments.
- **Financial & Operational Reporting:** Purchases (Paid/Partial/Unpaid, Received/Pending/Ordered), Sales/POS (Cash/bKash/Card, discounts, partial payments, returns), and 6-category branch Expenses.

---

## 2. Documented Feature Gaps (Not Implemented in Current StockMate Architecture)

No fake tables or mock statistics were invented for these items; they are documented transparently for client roadmap planning:

1. **Cross-Tenant Inter-Store Stock Transfers (Branch A → Branch B):**
   - *Current behavior:* StockMate's `transfers` module operates between `warehouses` **within the same `tenant_id` (Store)**. Because each Store has its own `tenant_id` and isolated product IDs, native cross-store transfers between two separate branches are not yet built.
   - *Demo handling:* Demonstrated real intra-branch warehouse transfers (`Main Warehouse` → `Retail Floor / Backroom`) with `Completed`, `Sent`, and `Pending` states.
   - *Recommended roadmap feature:* Add a central SKU master mapping + cross-tenant inter-branch transfer service with source deduction and destination receipt confirmation.
2. **Remote Stock Demand / Requisition & Management Approval Workflow:**
   - *Current behavior:* No `stock_requisitions` / `stock_demands` table or approval workflow exists in the codebase.
   - *Recommended roadmap feature:* Add a Branch Stock Requisition module (`Draft → Submitted → Approved/Rejected → Converted to Transfer`).
3. **Multi-Batch / Lot-Level Expiry Tracking:**
   - *Current behavior:* `products.expiry_date` stores a single expiry date per product SKU rather than multiple batch/lot expiry dates with FEFO deduction.
   - *Demo handling:* Populated realistic near-expiry dates (4–15 days) on perishable SKUs (Milk, Curd, Eggs, Bread, Fruits, Vegetables) using the real `products.expiry_date` column.
4. **Unified Consolidated All-Branches Executive Dashboard:**
   - *Current behavior:* The Owner dashboard displays live metrics for the currently selected branch; switching branches via the top-left Store Switcher updates all KPIs and charts in real time.
   - *Recommended roadmap feature:* Add an Owner-only "All Branches Consolidated" summary view aggregating Sales, Purchases, Expenses, and Inventory Valuation across all `Store::where('user_id', Auth::id())` tenants.
