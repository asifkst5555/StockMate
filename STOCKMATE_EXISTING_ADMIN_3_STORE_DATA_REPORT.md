# StockMate — Existing Admin 3-Store Grocery Data & Reconciliation Report

**Date:** 2026-11-23  
**Account:** `admin@stockmate.com` (ID `2`)  
**Currency:** Bangladeshi Taka (**BDT `৳`**) across all 3 stores

---

## 1. Per-Store Dataset Summary

| Metric | FreshMart Central (`ID 1`) | FreshMart Bheramara (`ID 387`) | FreshMart Mirpur (`ID 816`) | Combined 3-Store Total |
|---|---:|---:|---:|---:|
| **Warehouses** | 2 (`Central Main`, `Central Retail Floor`) | 2 (`Bheramara Main`, `Bheramara Retail Floor`) | 2 (`Mirpur Main`, `Mirpur Retail Floor`) | **6** |
| **Grocery Products (SKUs)** | `156` (`FMC-A-001..156`) | `156` (`FMB-A-001..156`) | `156` (`FMM-A-001..156`) | **468** |
| **Categories** | `10` | `10` | `10` | **30** |
| **Suppliers** | `8` | `8` | `8` | **24** |
| **Customers** | `13` | `13` | `13` | **39** |
| **Purchases** | `10` (`৳ 204,925`) | `10` (`৳ 204,925`) | `10` (`৳ 204,925`) | **30 (`৳ 614,775`)** |
| **Purchase Returns** | `1` | `1` | `1` | **3** |
| **POS Sales** | `25` (`৳ 28,572`) | `22` (`৳ 26,213`) | `22` (`৳ 26,213`) | **69 (`৳ 80,998`)** |
| **Sales Returns** | `2` | `2` | `2` | **6** |
| **Stock Adjustments** | `3` | `3` | `3` | **9** |
| **Warehouse Transfers** | `3` | `3` | `3` | **9** |
| **Expenses** | `10` (`৳ 164,625`) | `10` (`৳ 111,945`) | `10` (`৳ 125,115`) | **30 (`৳ 401,685`)** |
| **Low-Stock SKUs** | `12` | `12` | `12` | **36** |
| **Out-of-Stock SKUs** | `5` | `5` | `5` | **15** |
| **Inventory Valuation (Cost)** | **`৳ 2,118,112`** | **`৳ 1,531,149`** | **`৳ 1,677,981`** | **`৳ 5,327,242`** |

---

## 2. Stock Movement Ledger Reconciliation (`100% Exact Match`)

| Store | `stock_movements` Rows | `SUM(manage_stocks.quantity)` | `SUM(stock_movements.quantity_change)` | Variance | Status |
|---|---:|---:|---:|---:|---|
| **FreshMart Central (`ID 1`)** | `278` | `13,251` | `13,251` | `0` | **RECONCILED** |
| **FreshMart Bheramara (`ID 387`)** | `269` | `9,501` | `9,501` | `0` | **RECONCILED** |
| **FreshMart Mirpur (`ID 816`)** | `269` | `10,437` | `10,437` | `0` | **RECONCILED** |

---

## 3. Grocery Categories & Pack Variations Seeded Per Store

- **10 Categories:** Rice & Grains (`18`), Cooking Oil (`14`), Spices (`16`), Beverages (`16`), Snacks (`16`), Dairy (`14`), Frozen Food (`14`), Personal Care (`16`), Household Cleaning (`16`), Baby Products (`16`).
- **Pack Sizes / Units:** `1kg`, `500g`, `250g`, `5L`, `2L`, `1L`, and `1pc` mapped cleanly to `BaseUnit` (`kilogram`, `liter`, `piece`).
- **Bangladesh Brands:** ACI Pure, Teer, Rupchanda, Pran, Radhuni, Fresh, Aarong Dairy, Ispahani, Olympic, Bashundhara, Kazi Farms, Unilever BD, Square Toiletries, Marks, Nestle BD.
