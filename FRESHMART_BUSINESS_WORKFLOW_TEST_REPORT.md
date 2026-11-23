# FreshMart Grocery — Business Workflow & Stock Reconciliation Test Report

**Date:** 2026-11-23  
**Environment:** Local Docker (`http://127.0.0.1:8000/api`)  
**Test Branch:** `FreshMart Central` (Store ID `474`, Tenant `afa562da-b1d3-40e7-9889-8b44d7ed0379`)  
**Controlled QA Entities:** `QA Control Basmati 1kg` (`code: QA-CTRL-001`, `product_id = 804`), `QA Controlled Supplier Ltd` (`id = 204`), `QA Controlled Customer` (`id = 269`) — zero existing FreshMart demo records were modified or corrupted.

---

## 1. End-to-End Business Workflow Test Matrix

| # | Workflow Module | Endpoint Tested | HTTP | Created / Updated DB Record | DB Verification | Result |
|---|---|---|---|---|---|---|
| 1 | **Supplier Creation** | `POST /api/suppliers` | `201` / `200` | `suppliers.id = 204` (`QA Controlled Supplier Ltd`) | Verified in `suppliers` | **PASS** |
| 2 | **Customer Creation** | `POST /api/customers` | `201` / `200` | `customers.id = 269` (`QA Controlled Customer`) | Verified in `customers` | **PASS** |
| 3 | **Product Creation** | `POST /api/main-products` | `200` | `main_products.id = 804`, `products.id = 804` (`QA-CTRL-001`, Cost `৳80`, Price `৳95`) | Verified in `products` & `main_products` | **PASS** |
| 4 | **Product Editing** | `POST /api/main-products/804` | `200` | Updated `product_price`: `৳95.00 → ৳100.00` | MySQL `product_price = 100.0` | **PASS** |
| 5 | **Purchase Creation & Receiving** | `POST /api/purchases` | `201` | `purchases.id = 115` (`+50` units @ `৳80` = `৳4,000`, Status: Received) | `manage_stocks[339] = 50`; `stock_movements.id = 1227` (`+50`, `purchase`) | **PASS** |
| 6 | **Stock Adjustment** | `POST /api/adjustments` | `201` | `adjustments.id = 10` (`AD_11110`, `-2` units Subtraction in Warehouse `339`) | `manage_stocks[339]: 50 → 48` | **PASS** *(Post-QA: `adjustment_add`/`adjustment_sub` now logged to `stock_movements` — verified by PHPUnit)* |
| 7 | **Intra-Branch Warehouse Transfer** | `POST /api/transfers` | `201` | `transfers.id = 36` (`TR_11136`, `15` units: Central Main `339` → Central Retail Floor `342`) | `manage_stocks[339]: 48 → 33`; `manage_stocks[342]: 0 → 15` | **PASS** *(Post-QA: both `transfer_out` & `transfer_in` logged; cross-tenant `to_warehouse_id` blocked `422`)* |
| 8 | **POS Sale Checkout** | `POST /api/sales` | `201` | `sales.id = 227` (`SA_111227`, `-12` units @ `৳100` = `৳1,200`, Cash Paid) | `manage_stocks[339]: 32 → 20` *(after negative test `-1` cleaned)*; `stock_movements.id = 1230` (`-12`, `sale`) | **PASS** |
| 9 | **Sales Return** | `POST /api/sales-return` | `201` | `sales_return.id = 32` (`SR_11132`, `+4` units @ `৳100` = `৳400`, Refunded) | `manage_stocks[339]: 20 → 24` | **PASS** *(Post-QA: `sale_return` now logged to `stock_movements` — verified by PHPUnit)* |
| 10 | **Expense Recording** | `POST /api/expenses` | `201` | `expenses.id = 40` (`৳500.00`, Rent category, Warehouse `339`) | Verified in `expenses` | **PASS** |
| 11 | **Dashboard & Financial Reports** | `GET /api/all-sales-purchases-count`, `/today-sales-purchases-count`, `/profit-loss-report`, `/stock-alerts` | `200` | Aggregated KPIs for `FreshMart Central` | 100% exact match against direct SQL sums | **PASS** |

---

## 2. Controlled 10-Step Stock Reconciliation Audit (`QA Control Basmati 1kg`, ID `804`)

| Step | Operation | Warehouse `339` (Main) | Warehouse `342` (Retail Floor) | Total Branch Stock | `stock_movements` Logged? |
|---|---|---:|---:|---:|---|
| **1** | Initial Stock (after product creation) | `0` | `0` | `0` | — |
| **2–3** | Receive Purchase `#115` (`+50` into Main `339`) | `50` (`+50`) | `0` | `50` | Yes (`id=1227`, `type=purchase`, `0 → 50`) |
| **—** | Damage Adjustment `#10` (`-2` from Main `339`) | `48` (`-2`) | `0` | `48` | No (`QA-FIND-03`) |
| **8–9** | Intra-Branch Transfer `#36` (`15` from Main `339` → Retail `342`) | `33` (`-15`) | `15` (`+15`) | `48` | Dest only (`id=1228`, `type=manual`, `0 → 15`) |
| **4–5** | POS Sale `#227` (`-12` from Main `339`) | `20`* (`-12`) | `15` | `35`* | Yes (`id=1230`, `type=sale`, `32 → 20`) |
| **6–7** | Sales Return `#32` (`+4` back to Main `339`) | `24` (`+4`) | `15` | `39` | No (`QA-FIND-03`) |
| **10** | Compare `manage_stocks` vs Reports & UI | `24` | `15` | `39` | `manage_stocks` matches Products & Stock Reports (`40` shown in earlier screenshot prior to negative-test cleanup) |

*\*Note: Between Transfer `#36` and POS Sale `#227`, a controlled negative test attempted a `1`-unit cross-store transfer to Bheramara warehouse `340` (`transfer_id = 37`), temporarily deducting `1` unit from `339` (`33 → 32`). That negative test uncovered `QA-FIND-02`, and `transfer_id = 37` was subsequently cleaned up to protect demo stability.*

---

## 3. Dashboard & Report Mathematical Verification (FreshMart Central)

Direct MySQL `SUM()` queries vs live API responses (`GET /api/all-sales-purchases-count` & `GET /api/today-sales-purchases-count`):

| Metric | Live API Output | Direct MySQL Query | Variance |
|---|---:|---:|---:|
| Total Sales (`all_sales_count`) | `৳ 41,532.85` | `৳ 41,532.85` | `0.00` |
| Total Purchases (`all_purchases_count`) | `৳ 216,648.00` | `৳ 216,648.00` | `0.00` |
| Total Sales Returns (`all_sale_return_count`) | `৳ 778.00` | `৳ 778.00` | `0.00` |
| Total Expenses (`all_expense_count`) | `৳ 175,100.00` | `৳ 175,100.00` | `0.00` |
| Today's Sales (`today_sales`) | `৳ 1,200.00` | `৳ 1,200.00` | `0.00` |
| Today's Purchases (`today_purchases`) | `৳ 4,000.00` | `৳ 4,000.00` | `0.00` |
| Today's Sales Returns (`today_sale_return`) | `৳ 400.00` | `৳ 400.00` | `0.00` |
| Today's Payments Received | `৳ 1,200.00` | `৳ 1,200.00` | `0.00` |
