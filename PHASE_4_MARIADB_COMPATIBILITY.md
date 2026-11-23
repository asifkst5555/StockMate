# Phase 4 — MariaDB 10.5 Compatibility Review

Reviewed all **128 migrations** (`database/migrations/*.php`) and database queries against **Hostinger MariaDB 10.5 / InnoDB** (compared with local MySQL 8.0).

## 1. Migration & SQL Compatibility Matrix

| Check | Classification | Findings |
|---|---|---|
| MySQL 8-Only Collations (`utf8mb4_0900_ai_ci`) | **PASS** | **0 occurrences** across all 128 migrations and `config/database.php` (`charset => 'utf8mb4'`, `collation => 'utf8mb4_unicode_ci'`) |
| Unsupported MySQL 8 DDL (`fullText`, `spatialIndex`, `CHECK`, `generatedAs`, `invisible`) | **PASS** | **0 occurrences** across all 128 migrations |
| `->json()` Columns (3 migrations: `tenants`, `features`, `media`) | **PASS** | MariaDB 10.5 supports `JSON` as an alias for `LONGTEXT` with `CHECK (json_valid(...))`, fully compatible with Laravel 10 Eloquent `array`/`json` casts |
| `->change()` & `->renameColumn()` (6 migrations) | **PASS** | `doctrine/dbal: ^3.5` is in `composer.json` `require` (installed in `--no-dev`), supporting column modifications and renames on MariaDB 10.5 |
| `manage_stocks` Composite Unique Index (`warehouse_id, product_id`) | **PASS** | Both columns are `unsignedBigInteger` (8 bytes each), well under InnoDB's 3072-byte index key limit; additive migration merges any duplicate rows before creating the index |
| Tenant Backfill `JOIN UPDATE` (`2026_11_23_000001_add_tenant_isolation_and_stock_movements.php`) | **PASS** | Uses standard `UPDATE <table> INNER JOIN warehouses ON ... SET <table>.tenant_id = warehouses.tenant_id WHERE <table>.tenant_id IS NULL`, 100% valid on MariaDB 10.5 InnoDB |
| `jobs` Table Migration (`2026_11_23_000002_create_jobs_table.php`) | **PASS** | Standard Laravel InnoDB schema (`bigIncrements`, `string(255)` indexed, `longText`), guarded with `Schema::hasTable('jobs')` |
| Foreign Key Cascade & `tenants.id` (`string(255)`) | **PASS** | `tenants.id` and all referencing `tenant_id` columns are `VARCHAR(255)` with matching `utf8mb4_unicode_ci` collation; InnoDB FKs work identically on MariaDB 10.5 |
| Non-Strict Mode Dependency (`config/database.php`: `strict => false`) | **WARNING** | `DashboardAPIController::getTopSellingProducts()` relies on non-strict `GROUP BY products.id`. Keep `'strict' => false` in `config/database.php` (MariaDB 10.5 permits non-aggregated columns when `ONLY_FULL_GROUP_BY` is off) |
| Transaction Behavior (`lockForUpdate()`) | **PASS** | InnoDB row-level `SELECT ... FOR UPDATE` inside `DB::beginTransaction()` in `SaleRepository`, `TransferRepository`, and `manageStock()` behaves identically on MariaDB 10.5 InnoDB |

## 2. Verdict

**PASS (0 MariaDB 10.5 blockers)**. All 128 migrations and runtime queries are compatible with Hostinger MariaDB 10.5 / InnoDB without modifying any historical migrations.
