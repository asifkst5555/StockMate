# StockMate — NorthStar Market (USA) Data & Reconciliation Report

**Date:** 2026-11-23  
**Account:** `admin@stockmate.com` (ID `2`) | **Currency:** USD (`$`, 2 decimals)

---

## 1. Per-Store USD Dataset Summary

| Metric | Manhattan (`ID 1`) | Brooklyn (`ID 387`) | Queens (`ID 816`) | Combined 3-Store Total |
|---|---:|---:|---:|---:|
| **Warehouses** | 2 (`Backroom Stock`, `Sales Floor`) | 2 (`Backroom Stock`, `Sales Floor`) | 2 (`Backroom Stock`, `Sales Floor`) | **6** |
| **American Grocery SKUs** | `159` (`NSM-MN-001..159`) | `159` (`NSM-BK-001..159`) | `159` (`NSM-QN-001..159`) | **477** |
| **Categories** | `14` | `14` | `14` | **42** |
| **Suppliers** | `8` (UNFI, Sysco, C&S, KeHE, etc.) | `8` | `8` | **24** |
| **Customers** | `13` | `13` | `13` | **39** |
| **Purchases (USD)** | `10` (`$4,575.90`) | `10` (`$4,575.90`) | `10` (`$4,575.90`) | **30 (`$13,727.70`)** |
| **Purchase Returns** | `1` | `1` | `1` | **3** |
| **POS Sales (USD)** | `26` (`$1,167.63`) | `23` (`$1,012.87`) | `22` (`$949.55`) | **71 (`$3,130.05`)** |
| **Sales Returns** | `2` | `2` | `2` | **6** |
| **Stock Adjustments** | `3` | `3` | `3` | **9** |
| **Warehouse Transfers** | `3` | `3` | `3` | **9** |
| **Operating Expenses (USD)** | `10` (`$25,764.75`) | `10` (`$20,039.25`) | `10` (`$18,130.75`) | **30 (`$63,934.75`)** |
| **Low-Stock SKUs** | `15` | `15` | `15` | **45** |
| **Out-of-Stock SKUs** | `5` | `5` | `5` | **15** |
| **Inventory Valuation (Cost USD)** | **`$49,010.30`** | **`$40,448.42`** | **`$37,027.21`** | **`$126,485.93`** |

## 2. Stock Movement Ledger Reconciliation (`100% Exact Match`)

| Store | `stock_movements` Rows | `SUM(manage_stocks.quantity)` | `SUM(stock_movements.quantity_change)` | Variance | Status |
|---|---:|---:|---:|---:|---|
| **NorthStar Market – Manhattan (`ID 1`)** | `320` | `12,328` | `12,328` | `0` | **RECONCILED** |
| **NorthStar Market – Brooklyn (`ID 387`)** | `308` | `10,206` | `10,206` | `0` | **RECONCILED** |
| **NorthStar Market – Queens (`ID 816`)** | `304` | `9,359` | `9,359` | `0` | **RECONCILED** |
