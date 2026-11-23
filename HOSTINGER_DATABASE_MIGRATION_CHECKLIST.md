# Hostinger Database Migration Safety Checklist

**Target Database**: Hostinger MariaDB `10.5` / InnoDB.
**Total Migrations**: **128** (126 original + 2 safe additive migrations: `2026_11_23_000001_add_tenant_isolation_and_stock_movements.php` and `2026_11_23_000002_create_jobs_table.php`).

---

## Strict Prohibitions (NEVER RUN ON PRODUCTION)

- NEVER run `php artisan migrate:fresh`
- NEVER run `php artisan migrate:refresh`
- NEVER run `php artisan migrate:reset`
- NEVER run `php artisan db:wipe`
- NEVER `TRUNCATE` or `DROP` business tables

---

## Pre-Migration Checklist

- [ ] Take a full SQL backup via Hostinger hPanel (*Databases -> Backups*) or `mysqldump` before running any migration.
- [ ] Verify `.env` points to the intended production database (`DB_DATABASE`, `DB_USERNAME`).
- [ ] Put the application in maintenance mode during schema changes:
  ```bash
  php artisan down --retry=60
  ```
- [ ] Inspect pending migrations first:
  ```bash
  php artisan migrate:status
  ```

## Review of Data-Mutating & Additive Migrations

1. **Legacy Data-Mutating Migrations (Original 126)**:
   - `2023_12_22_065227_fill_up_product_code.php`
   - `2023_12_29_065039_fill_up_main_product_table_data.php`
   - `2024_01_12_093843_move_product_images_to_main_product.php`
   - `2025_07_07_120230_run_generate_crud_permission_seeder.php` (invokes `GenerateCrudPermissionsSeeder` inline)
   - `2025_07_09_091831_create_payment_methods_table.php` (seeds default payment methods per tenant)
   - *Action*: On a fresh database, all run cleanly in sequence. On an existing database where these are already recorded in `migrations`, Laravel skips them automatically.

2. **New Additive Migration #1 — `2026_11_23_000001_add_tenant_isolation_and_stock_movements.php`**:
   - Guarded with `Schema::hasTable()` / `Schema::hasColumn()` checks.
   - Adds nullable `tenant_id` + FK (`tenants.id`) to `purchases`, `purchases_return`, `sales_return`, `expenses`, `holds`, `manage_stocks`, `coupon_codes`.
   - Backfills `tenant_id` from `warehouses.tenant_id` on existing rows where `tenant_id IS NULL`.
   - Detects any duplicate `(warehouse_id, product_id)` rows in `manage_stocks`, sums their quantities onto the lowest `id`, removes duplicate rows, and adds unique index `manage_stocks_warehouse_product_unique`.
   - Creates `stock_movements` table.
   - *MariaDB 10.5 Compatibility*: Uses standard InnoDB `BIGINT UNSIGNED`, `VARCHAR(255)`, `DOUBLE`, and standard `JOIN UPDATE` syntax fully supported by MariaDB 10.5.

3. **New Additive Migration #2 — `2026_11_23_000002_create_jobs_table.php`**:
   - Guarded with `if (! Schema::hasTable('jobs'))`.
   - Creates standard Laravel `jobs` table for `QUEUE_CONNECTION=database`.

## Execution & Post-Migration Verification

```bash
php artisan migrate --force
php artisan migrate:status
# First-time EMPTY database ONLY (do NOT run on populated production DB):
# php artisan db:seed --force
php artisan optimize
php artisan up
```

- [ ] Verify `SELECT COUNT(*) FROM migrations;` equals `128`.
- [ ] Verify `stock_movements` and `jobs` tables exist.
- [ ] Verify zero `NULL` `tenant_id` values remain on warehouse-linked business records.
- [ ] Verify `storage/logs/laravel-*.log` has zero SQL errors.
