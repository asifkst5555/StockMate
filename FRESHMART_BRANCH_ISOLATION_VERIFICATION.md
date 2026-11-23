# FreshMart Grocery — Multi-Branch & Role Isolation Verification

**Date:** 2026-11-23  
**Environment:** Local Docker (`http://127.0.0.1:8000/api`)  
**Scope:** All 8 FreshMart demo accounts tested against all 3 FreshMart branches (`474: Central`, `475: Bheramara`, `476: Mirpur`) plus an external non-FreshMart tenant store (`1: My Store`).

---

## 1. Branch Visibility & Store-Switch Authorization Matrix

| Account | Role | `GET /api/stores` Visible Branches | Switch Central (`474`) | Switch Bheramara (`475`) | Switch Mirpur (`476`) | Switch External Store (`1`) | Isolation Result |
|---|---|---|---:|---:|---:|---:|---|
| `owner@freshmart.demo` | Store Owner | `474`, `475`, `476` | `200` | `200` | `200` | **`403`** | **PASS** |
| `manager.central@freshmart.demo` | Central Manager | `474` only | `200` | **`403`** | **`403`** | **`403`** | **PASS** |
| `manager.bheramara@freshmart.demo` | Bheramara Manager | `475` only | **`403`** | `200` | **`403`** | **`403`** | **PASS** |
| `manager.mirpur@freshmart.demo` | Mirpur Manager | `476` only | **`403`** | **`403`** | `200` | **`403`** | **PASS** |
| `inventory@freshmart.demo` | Inventory Officer | `474`, `475`, `476` | `200` | `200` | `200` | **`403`** | **PASS** |
| `cashier.central@freshmart.demo` | Cashier Central | `474` only | `200` | **`403`** | **`403`** | **`403`** | **PASS** |
| `cashier.bheramara@freshmart.demo` | Cashier Bheramara | `475` only | **`403`** | `200` | **`403`** | **`403`** | **PASS** |
| `cashier.mirpur@freshmart.demo` | Cashier Mirpur | `476` only | **`403`** | **`403`** | `200` | **`403`** | **PASS** |

---

## 2. Direct Cross-Tenant Record ID Lookup Verification

Authenticated as `manager.central@freshmart.demo` (assigned exclusively to `FreshMart Central`, Store `474`):

| Attack / Probe | Target Tenant | HTTP Status | Data Returned | Result |
|---|---|---:|---|---|
| `GET /api/products/474` | `FreshMart Bheramara` product | **`422`** | None (ModelNotFound under tenant scope) | **PASS — Isolated** |
| `GET /api/sales/180` | `FreshMart Mirpur` sale | **`422`** | None (ModelNotFound under tenant scope) | **PASS — Isolated** |
| `GET /api/purchases/88` | `FreshMart Bheramara` purchase | **`422`** | None (ModelNotFound under tenant scope) | **PASS — Isolated** |

---

## 3. Module-Level RBAC Verification

| Role Persona | Products | Purchases | Sales/POS | Expenses (`GET` / `POST`) | Transfers | Adjustments | Users (`GET` / `POST`) | Dashboard |
|---|---|---|---|---|---|---|---|---|
| **FreshMart Owner** | `200` | `200` | `200` | `200` / `201` | `200` | `200` | `200` / Allowed | `200` |
| **Branch Managers (×3)** | `200` | `200` | `200` | `200` / Allowed | `200` | `200` | `200` / Scoped | `200` |
| **Inventory Officer** | `200` | `200` | `200` | `200` / **`403`** | `200` | `200` | `200` / **`403`** | `200` |
| **POS Cashiers (×3)** | `200` | **`403`** | `200` | **`403`** / **`403`** | **`403`** | **`403`** | **`403`** / **`403`** | **`403`** |

*Post-QA Update (`QA-FIND-01` & `QA-FIND-02` Resolved): Inventory Officer `POST/PUT/DELETE /api/expenses` now strictly returns **`403`** (`manage_expenses` required), and cross-tenant `POST /api/transfers` (`to_warehouse_id` from another branch) now strictly returns **`422`**. Full details in [FRESHMART_POST_QA_REMEDIATION_REPORT.md](file:///Users/macminim4/Projects/digital-inventory-with-pos/FRESHMART_POST_QA_REMEDIATION_REPORT.md).*

---

## 4. Visual Proof of Branch Isolation

- **Owner (`FreshMart Central`):** [01-owner-dashboard-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/01-owner-dashboard-1440x900.png) shows `FreshMart Central` KPIs (`Sales ৳ 32.6K`, `Purchases ৳ 187.2K`, `Today Total Sales ৳ 1.2K`) and all 3 branches in [02-branch-selector-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/02-branch-selector-1440x900.png).
- **Branch Manager (`FreshMart Bheramara`):** [14-branch-manager-dashboard-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/14-branch-manager-dashboard-1440x900.png) shows exclusively `FreshMart Bheramara` KPIs (`Sales ৳ 31.9K`, `Purchases ৳ 73.8K`, `Today Total Sales ৳ 0.00`) and a restricted sidebar without Store/Settings administration.
- **Cashier (`FreshMart Central`):** [15-cashier-pos-1440x900.png](file:///Users/macminim4/Projects/digital-inventory-with-pos/docs/freshmart-qa-screenshots/15-cashier-pos-1440x900.png) opens directly into the POS register with zero access to Purchases, Expenses, Transfers, or Admin Settings.
