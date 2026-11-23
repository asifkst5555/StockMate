# Phase 5: Database Audit

## 1. Schema & Migration Inventory

- **Total Migrations**: 126 migration files under `database/migrations/` (spanning `2014_10_12` through `2025_07_09`).
- **Configured Engine**: MySQL (`config/database.php`: `charset => 'utf8mb4'`, `collation => 'utf8mb4_unicode_ci'`, `strict => false`).
- **Seeders**: 11 seeders under `database/seeders/`. `DatabaseSeeder` invokes 9 seeders (`DefaultCountriesSeeder`, `DefaultLanguageTableSeeder`, `AddUserIdInTransactionSeeder`, `DefaultFrontDataSeeder`, `DefaultPermissionsSeeder`, `DefaultUserSeeder`, `DefaultSettingSeeder`, `DefaultFeaturesSeeder`, `GenerateCrudPermissionsSeeder`).

## 2. Verified Migration & Schema Findings

1. **Missing `tenant_id` on Core Transactional Tables**:
   - Tables with `tenant_id` FK -> `tenants.id`: `users`, `stores`, `warehouses`, `suppliers`, `customers`, `brands`, `product_categories`, `units`, `base_units`, `main_products`, `products`, `variations`, `sales`, `quotations`, `transfers`, `adjustments`, `expense_categories`, `pos_register`, `payment_methods`, `settings`, `mail_templates`, `sms_templates`, `sms_settings`, `taxes`, `transactions`, `roles`.
   - Core tables **lacking** `tenant_id`: `purchases`, `purchases_return`, `sales_return`, `expenses`, `holds`, `manage_stocks`, `coupon_codes`. These rely solely on indirect joins (`warehouse_id` / `sale_id`) which are omitted in several controller methods.
2. **Missing Unique Constraint on `manage_stocks(warehouse_id, product_id)`**:
   - `manage_stocks` has no composite unique index on `(warehouse_id, product_id)`. Concurrent inserts in `manageStock()` can create duplicate stock rows for the same product & warehouse.
3. **Data-Mutating Migrations Mixed With Schema Migrations**:
   - `2023_12_22_065227_fill_up_product_code.php` queries `Product::where('product_code', '')->get()`.
   - `2023_12_29_065039_fill_up_main_product_table_data.php` mutates `MainProduct`.
   - `2024_01_12_093843_move_product_images_to_main_product.php` moves media records.
   - `2025_07_07_120230_run_generate_crud_permission_seeder.php` runs `Artisan::call('db:seed', ['--class' => 'GenerateCrudPermissionsSeeder', '--force' => true])` during `php artisan migrate`!
   - `2025_07_09_091831_create_payment_methods_table.php` seeds default payment methods and updates `sales` and `sales_payments` inline.
   - **Risk**: Because `DatabaseSeeder` also calls `GenerateCrudPermissionsSeeder`, seeders must be verified for idempotency before execution.
4. **Typo in Migration Filename (Do Not Rename)**:
   - `2022_03_08_055549_creat_sale_items_table.php` (`creat` instead of `create`). Preserved as-is to avoid breaking existing `migrations` table tracking.
5. **MySQL `strict => false` Dependency**:
   - `config/database.php` sets `'strict' => false`. Dashboard queries (`DashboardAPIController::getTopSellingProducts`, `getWeekSalePurchases`) depend on non-strict `GROUP BY` and MySQL `DATE_FORMAT()`.
6. **Seeder Non-Idempotency Risk**:
   - `DefaultUserSeeder` uses `Role::create(...)`, `User::create(...)`, `Currency::create(...)`, `Plan::create(...)` instead of `firstOrCreate()` — running `db:seed` twice will fail on duplicate unique constraints (`email`, `roles.name`).
7. **Missing Stock Ledger Table**:
   - No `stock_movements` / `stock_histories` table exists to audit stock changes over time.
8. **Foreign Key & Cascade Review**:
   - `sales`, `purchases`, `transfers`, `adjustments`, `quotations`, `holds` use foreign keys to `warehouses` / `customers` / `suppliers`, and `tenant_id` uses `onUpdate('cascade')->onDelete('cascade')`. Deleting a tenant cascades across all tenant-scoped tables, but orphaned rows may remain in tables without `tenant_id` if not chained through `warehouses`.

## 3. Safe Upgrade Recommendations (Non-Destructive)

- Do **not** edit or delete existing migrations.
- Add a dedicated additive migration (when database runtime is available) to add composite unique index `(warehouse_id, product_id)` on `manage_stocks` (after verifying no duplicates) and indexes on high-traffic filter columns (`sales.date`, `sales.tenant_id`, `purchases.date`, `sales_payments.sale_id`).
