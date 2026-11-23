# StockMate — Existing Admin (`admin@stockmate.com`) 3-Store Pre-Implementation Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Environment:** Local Docker (`http://127.0.0.1:8000`)

---

## 1. Existing Account & Subscription State (Inspected Before Changes)

| Field | Inspected Value |
|---|---|
| User ID | `2` |
| Email | `admin@stockmate.com` |
| Status | `1` (Active) |
| Active `tenant_id` | `f58e82d5-2dd0-460a-be33-2a6b8cda9ad1` |
| `store_limit_override` | `null` |
| Active Subscription | Subscription `#1` — `Default Plan` (`plan_no_of_stores = 10`, Status: Active) |
| BDT Currency Record | `currencies.id = 3` (`Bangladesh`, `BDT`, `৳`) |

Because `Default Plan` already grants up to `10` stores, no modification to the global `Default Plan` or `store_limit_override` was required to host 3 stores under `admin@stockmate.com`.

---

## 2. Existing Stores Owned by `admin@stockmate.com` (Before Seeding)

| Store ID | Original Name | Tenant ID | Warehouses | Products | Purchases | Sales | Expenses | Currency Setting | Payment Methods |
|---:|---|---|---|---:|---:|---:|---:|---|---|
| `1` | `My Store` | `f58e82d5-2dd0-460a-be33-2a6b8cda9ad1` | `1` (`warehouse`) | `0` | `0` | `0` | `0` | `2` (USD) | `0` |
| `387` | `Store 02` | `5f26af71-9a8a-4492-9717-ae613911a96c` | `1` (`warehouse`) | `0` | `0` | `0` | `0` | `2` (USD) | `4` (Cash, Cheque, Bank Transfer, Other) |

---

## 3. Architectural & Safety Findings

1. **Per-Store Tenant Architecture:** In StockMate, each `Store` has its own `MultiTenant` (`tenant_id`) and is linked to its owner via `stores.user_id = 2`. Switching stores updates `users.tenant_id` to the selected store's `tenant_id`.
2. **Safe Reuse Plan:** Store `1` (`My Store`) and Store `387` (`Store 02`) contained `0` products and `0` transactions; both were safely reused and renamed (preserving their IDs, tenant IDs, and existing default warehouse & walk-in customer), and only the missing 3rd store was created via `StoreRepository::store()`.
3. **Store 1 Payment Methods Gap Identified:** Store `1` had `0` payment methods configured; the seeder provisioned `Cash`, `bKash / Mobile Banking`, and `Card / Bank Transfer` for all 3 stores so POS & Purchases work identically across branches.
4. **Transfer Scope Transparency:** StockMate's `transfers` table operates between warehouses within the same store (`tenant_id`), and cross-tenant destination warehouses are strictly blocked (`422`) by tenant-scoped validation. Accordingly, each store was provisioned with 2 warehouses (`<Branch> Main Warehouse` and `<Branch> Retail Floor`) to demonstrate full transfer workflows cleanly without weakening tenant isolation.
