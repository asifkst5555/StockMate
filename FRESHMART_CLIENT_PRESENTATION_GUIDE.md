# StockMate — FreshMart Grocery Client Presentation Guide

**Target Audience:** Multi-branch grocery retail owner / operations team  
**Demo Environment:** `http://127.0.0.1:8000/app/login`  
**Demo Duration:** ~15–20 minutes  
**Currency:** Bangladeshi Taka (**BDT `৳`**)

---

## 1. Pre-Demo Checklist (2 Minutes Before Presenting)

1. Confirm local Docker containers are running (`http://127.0.0.1:8000/app/login` loads cleanly).
2. Keep [FRESHMART_DEMO_CREDENTIALS_LOCAL.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_DEMO_CREDENTIALS_LOCAL.md) handy for switching between Owner, Branch Manager, and Cashier personas.
3. Use a `1440×900` (or `1920×1080`) browser window for best presentation clarity. All post-QA security, ledger, and UI fixes (`QA-FIND-01`–`QA-FIND-05`) are live and verified (`39/39` PHPUnit tests passing, `0px` horizontal overflow on Reports, and unit badges displaying cleanly across all 162 grocery SKUs).

---

## 2. Step-by-Step Live Walkthrough Script

### Act 1 — Executive Multi-Branch Control (5 Minutes)
- **Login:** `owner@freshmart.demo` / `FreshMart@Owner2026`
- **Show Dashboard:** Highlight live BDT (`৳`) KPIs (Sales, Purchases, Sales Returns, Today's Collections, Expenses), the Weekly Sales & Purchases chart, and Top Selling Products.
- **Show Branch Switching:** Click the top-left **Store Switcher** (`FreshMart Central`) to reveal all 3 branches (`FreshMart Central`, `FreshMart Bheramara`, `FreshMart Mirpur`). Switch to `FreshMart Bheramara` and point out how every KPI, chart, and stock figure updates instantly to that branch's isolated data. Switch back to `FreshMart Central`.
- **Talking Point:** *"One owner account controls all three branches with complete data separation—no spreadsheets or manual branch consolidation required."*

### Act 2 — Grocery Catalog, Pricing & Stock Alerts (3 Minutes)
- **Navigate to Products (`/app/user/products`):** Show the 162 realistic Bangladesh grocery SKUs (Rice, Cooking Oil, Dal, Spices, Dairy, Beverages, Household) with BDT pricing and live stock counts.
- **Open Product Details (`/app/user/products/detail/312`):** Show barcode, cost vs selling price margin, category, brand, and warehouse stock breakdown.
- **Navigate to Low-Stock Report (`/app/user/report/report-product-quantity`):** Show items below their `stock_alert` threshold that need immediate supplier restocking.

### Act 3 — Purchasing, Warehouse Transfers & Profitability (4 Minutes)
- **Navigate to Purchases (`/app/user/purchases`):** Open a purchase detail view to show supplier invoice tracking, received status, and automated stock increment.
- **Navigate to Transfers (`/app/user/transfers`):** Show intra-branch warehouse replenishment (`Central Main Warehouse` → `Central Retail Floor`) with `Completed`, `Sent`, and `Pending` statuses.
- **Navigate to Profit & Loss (`/app/user/report/profit-loss`):** Walk through Sales, Purchases, Returns, Expenses, Revenue, Gross Profit, and Payments Received in BDT (`৳`).

### Act 4 — Strict Branch Manager & Cashier Isolation (4 Minutes)
- **Log out → Log in as `manager.bheramara@freshmart.demo` / `FreshMart@Mgr2026`:**
  - Point out that Farhana Islam sees **only** `FreshMart Bheramara` and cannot view Central or Mirpur financials or system settings.
- **Log out → Log in as `cashier.central@freshmart.demo` / `FreshMart@Pos2026`:**
  - Open **POS (`/app/user/pos`)**: Demonstrate fast barcode/name search (`Miniket Rice 1kg`), category/brand filters, adding items to cart, Cash or bKash payment selection, and instant receipt + real-time stock deduction.

---

## 3. Handling Client Roadmap Questions Transparently

If the client asks about features outside the current release, answer honestly using our verified roadmap:

- **"Can we transfer stock directly from FreshMart Central to FreshMart Mirpur?"**  
  *Answer:* Today, transfers move stock between warehouses inside a branch (e.g., Main Warehouse → Retail Floor). Direct cross-branch inter-store transfers with transit confirmation are planned as our Phase 2 multi-branch enhancement.
- **"Can branch managers submit stock demand requests for Owner approval?"**  
  *Answer:* A formal Branch Requisition & Approval workflow is scoped on the Phase 2 roadmap alongside inter-branch transfers.
- **"Can we track multiple expiry batches for the same milk or bread SKU?"**  
  *Answer:* Currently each SKU tracks a primary expiry date for perishable monitoring; multi-batch/lot FEFO tracking is planned for Phase 3.
- **"Can the Owner see one combined total across all 3 branches on a single screen?"**  
  *Answer:* Today the Owner switches branches in one click via the Store Switcher; a unified "All-Branches Executive Rollup" widget is planned for Phase 2.
