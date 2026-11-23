# FreshMart Grocery — StockMate Database & Feature Schema Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Database:** `digital_inventory_pos` (MySQL 8.0 via local Docker)

---

## 1. Multi-Tenant & Multi-Store Architecture Verified

| Table | Key Columns | Actual Architectural Behavior |
|---|---|---|
| `stores` | `id`, `name`, `tenant_id`, `user_id`, `status` | One business owner (`users.id`) owns multiple `stores`. Each `Store` maps 1-to-1 to a `tenants` row (`tenant_id`). |
| `tenants` | `id` (UUID), `store_id`, `data` | Single-database multi-tenancy via `stancl/tenancy` + `App\Traits\Multitenantable` (`tenant_id` scoping). |
| `users` | `id`, `first_name`, `last_name`, `email`, `phone`, `tenant_id`, `status`, `store_limit_override` | Owner has `Role::ADMIN` + active `Subscription` + `store_limit_override`. Sub-users link to authorized stores via `user_stores`. |
| `user_stores` | `id`, `user_id`, `store_id` | Pivot table controlling which branches a Branch Manager, Inventory Officer, or Cashier may access. |
| `roles` / `permissions` | Spatie Permission tables + `roles.tenant_id` | Custom roles are tenant-scoped (`roles.tenant_id`). 109 seeded permissions (coarse `manage_*` + fine-grained `view/create/edit/delete_*`). |
| `plans` / `subscriptions` | `plans.no_of_stores`, `users.store_limit_override` | Enforced centrally by `App\Services\StoreLimitService`. Per-customer override (`store_limit_override = 3`) grants 3 branches without altering the global default plan. |

---

## 2. Core Inventory, POS, & Financial Tables Verified

| Module | Actual Table(s) | Status Constants & Key Relationships |
|---|---|---|
| **Catalog** | `product_categories`, `brands`, `base_units`, `units`, `variations`, `variation_types`, `main_products`, `products` | `products` links to `main_product_id`, `product_category_id`, `brand_id`, `product_unit`/`sale_unit`/`purchase_unit` (IDs), `stock_alert`, and **`expiry_date`**. |
| **Inventory & Ledger** | `warehouses`, `manage_stocks`, `stock_movements` | `manage_stocks` tracks `(tenant_id, warehouse_id, product_id, quantity, alert)`. `stock_movements` logs `(type, quantity_change, quantity_before, quantity_after, unit_cost, reference_type, reference_id, note, user_id)`. |
| **Parties** | `suppliers`, `customers` | Scoped per `tenant_id`. |
| **Purchases** | `purchases`, `purchase_items` | Status: `RECEIVED = 1`, `PENDING = 2`, `ORDERED = 3`. Payment Status: `PAID = 1`, `UNPAID = 2`, `PARTIAL = 3`. Payment Type: `CASH = 1`, `CHEQUE = 2`, `BANK_TRANSFER = 3`, `OTHER = 4`. |
| **Sales & POS** | `sales`, `sale_items`, `sales_payments`, `sales_return`, `sale_return_items`, `payment_methods` | Status: `COMPLETED = 1`, `PENDING = 2`, `ORDERED = 3`. Payment Status: `PAID = 1`, `UNPAID = 2`, `PARTIAL_PAID = 3`. |
| **Stock Transfers** | `transfers`, `transfer_items` | Status: `COMPLETED = 1`, `SENT = 2`, `PENDING = 3`. Operates between `from_warehouse_id` and `to_warehouse_id` **within the same `tenant_id`**. |
| **Stock Adjustments** | `adjustments`, `adjustment_items` | `method_type`: `METHOD_ADDITION = 1`, `METHOD_SUBTRACTION = 2`. |
| **Expenses** | `expense_categories`, `expenses` | Linked to `warehouse_id`, `expense_category_id`, `user_id`, `amount`, `date`, `title`, `details`. |

---

## 3. Supported vs. Missing/Partial Workflows Identified

| Requested Workflow | Status in StockMate | Demo Handling |
|---|---|---|
| Multi-branch under 1 Owner + Store Switcher | **Supported** | 1 dedicated FreshMart Owner (`owner@freshmart.demo`) owning 3 branches (`FreshMart Central`, `FreshMart Bheramara`, `FreshMart Mirpur`) with `store_limit_override = 3`. |
| Role-scoped Branch Managers, Inventory Officer, Cashiers | **Supported** | 8 demo users with custom tenant roles and `user_stores` assignments strictly restricting branch access. |
| Grocery Catalog (150–200 SKUs, 18 categories, BDT ৳) | **Supported** | 162 realistic Bangladesh grocery products per branch with unique SKUs, BDT currency (`৳`), units, brands, and `expiry_date`. |
| Branch-wise Inventory, Low-Stock, Out-of-Stock, Valuation | **Supported** | Distinct stock levels per branch in `manage_stocks` + reconciled `stock_movements` ledger. |
| Purchases (Received, Pending, Ordered, Paid/Partial/Unpaid) | **Supported** | 36 reconciled purchases across 3 branches (`12` per branch). |
| Sales & POS (Cash/Bank, Paid/Partial, Discounts, Returns) | **Supported** | 120 reconciled sales + `sales_payments` + sales returns across 3 branches (`40` per branch). |
| Warehouse-to-Warehouse Transfers | **Supported (Intra-Store)** | 9 reconciled transfers (`Completed`, `Sent`, `Pending`) across branch warehouses (e.g., Central Main Warehouse ↔ Central Retail Floor / Dispatch Hub). Cross-tenant inter-store transfers documented as feature gap. |
| Expiry Dates & Damage/Correction Adjustments | **Supported (Single Expiry Date + Adjustments)** | Perishable SKUs populated with near-expiry/expiring `expiry_date` + reconciled subtraction/addition `adjustments`. Multi-batch lot tracking documented as feature gap. |
| Branch Expenses (Rent, Electricity, Salaries, etc.) | **Supported** | 36 realistic expenses across 6 categories and 3 branches. |
| Remote Stock Demand / Requisition & Approval | **Unsupported** | Documented honestly in `FRESHMART_DEMO_FEATURE_GAP_REPORT.md` (no fake tables created). |
| Single Consolidated All-Branches Dashboard View | **Unsupported** | Owner switches seamlessly between all 3 branches via Store Switcher; consolidated cross-tenant BI view documented as feature gap. |
