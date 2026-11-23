# FreshMart Grocery — Client Demo QA Screenshot Index

**Date:** 2026-11-23  
**Capture Tool:** Headless Google Chrome for Testing (real login sessions against `http://127.0.0.1:8000`)  
**Resolutions:** Desktop `1440×900` (14 captures) & Mobile Responsive `390×844` (1 capture)

---

## Desktop Screenshots (`1440×900`)

| # | Screen / Persona | Route Captured | Layout Check | Screenshot File |
|---|---|---|---|---|
| 1 | **Owner Dashboard** (`FreshMart Central`) | `/app/user/dashboard` | `1435 ≤ 1440` (Clean) | [01-owner-dashboard-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/01-owner-dashboard-1440x900.png) |
| 2 | **Branch Selector Dropdown** (All 3 Branches) | `/app/user/dashboard` | `1435 ≤ 1440` (Clean, shows Central, Bheramara, Mirpur) | [02-branch-selector-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/02-branch-selector-1440x900.png) |
| 3 | **Products List** (162 Grocery SKUs + QA Control SKU) | `/app/user/products` | `1435 ≤ 1440` (Clean, `kilogram` badges — `0` `N/A`) | [03-products-list-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/03-products-list-1440x900.png) |
| 4 | **Product Details** (`Miniket Rice 1kg`) | `/app/user/products/detail/312` | `1435 ≤ 1440` (Clean) | [04-product-details-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/04-product-details-1440x900.png) |
| 5 | **Purchase List** | `/app/user/purchases` | `1440 ≤ 1440` (Clean) | [05-purchase-list-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/05-purchase-list-1440x900.png) |
| 6 | **Purchase Details** (`Purchase #115`, QA `+50`) | `/app/user/purchases/detail/115` | `1435 ≤ 1440` (Clean) | [06-purchase-details-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/06-purchase-details-1440x900.png) |
| 7 | **POS Screen** (Owner View, BDT `৳`) | `/app/user/pos` | `1440 ≤ 1440` (Clean) | [07-pos-screen-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/07-pos-screen-1440x900.png) |
| 8 | **Completed Sale Details** (`SA_111227`) | `/app/user/sales/detail/227` | `1435 ≤ 1440` (Clean) | [08-completed-sale-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/08-completed-sale-1440x900.png) |
| 9 | **Store Inventory** (Stock Report) | `/app/user/report/report-stock` | `1440 ≤ 1440` (Clean — `QA-FIND-04` resolved) | [09-store-inventory-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/09-store-inventory-1440x900.png) |
| 10 | **Warehouse Transfers** (`TR_11136` + Seeded Transfers) | `/app/user/transfers` | `1440 ≤ 1440` (Clean) | [10-transfer-screen-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/10-transfer-screen-1440x900.png) |
| 11 | **Reports — Profit & Loss** (BDT `৳`) | `/app/user/report/profit-loss` | `1435 ≤ 1440` (Clean — `QA-FIND-04` resolved) | [11-reports-profit-loss-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/11-reports-profit-loss-1440x900.png) |
| 12 | **Low-Stock Report** (Product Quantity Alerts) | `/app/user/report/report-product-quantity` | `1435 ≤ 1440` (Clean — `QA-FIND-04` resolved) | [12-low-stock-report-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/12-low-stock-report-1440x900.png) |
| 14 | **Branch Manager Dashboard** (`FreshMart Bheramara`) | `/app/user/dashboard` | `1440 ≤ 1440` (Clean, isolated Bheramara KPIs) | [14-branch-manager-dashboard-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/14-branch-manager-dashboard-1440x900.png) |
| 15 | **Cashier POS** (`FreshMart Central`, Cashier Role) | `/app/user/pos` | `1440 ≤ 1440` (Clean, POS-only access) | [15-cashier-pos-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/15-cashier-pos-1440x900.png) |

---

## Mobile Responsive Screenshot (`390×844`)

| # | Screen / Persona | Route Captured | Responsive Layout Check | Screenshot File |
|---|---|---|---|---|
| 13 | **Owner Dashboard (Mobile)** | `/app/user/dashboard` | `scrollWidth: 390 === innerWidth: 390` (**0px horizontal overflow**); KPI cards stack cleanly; minor date wrapping in topbar | [13-mobile-responsive-390x844.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/13-mobile-responsive-390x844.png) |
