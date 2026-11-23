# Hostinger Staging Rollback Plan

Use this procedure if a staging deployment, migration, or configuration change fails.

## 1. Immediate Stabilization (Maintenance Mode)

```bash
ssh <ssh_user>@<hostinger_host>
cd ~/domains/staging.yourdomain.com/pos-app
php artisan down --retry=60
```

## 2. Database Rollback (Safe Backup Restore)

> **Important**: Never run `migrate:fresh` or `db:wipe`. Do **not** rely on `php artisan migrate:rollback` for data-mutating migrations.

1. If restoring a pre-deployment staging backup:
   ```bash
   mysql -u <staging_db_user> -p <staging_db_name> < ~/backups/staging_pre_deploy_<timestamp>.sql
   ```
   *(Or restore directly via Hostinger hPanel -> Databases -> Backups.)*

2. If rolling back **only** the two additive Phase 2/3 migrations on an otherwise healthy staging DB:
   ```bash
   php artisan migrate:rollback --step=2 --force
   ```
   *(Both `2026_11_23_000002_create_jobs_table` and `2026_11_23_000001_add_tenant_isolation_and_stock_movements` implement safe `down()` methods that drop only the newly added `jobs` table, `stock_movements` table, unique index, and added `tenant_id` columns without touching original business tables.)*

## 3. Application & Asset Rollback

1. Preserve `public_html/uploads/` and `pos-app/storage/app/private/` (never delete user media).
2. Swap back to the previous release archive or backup directory:
   ```bash
   cd ~/domains/staging.yourdomain.com
   mv pos-app pos-app-failed-$(date +%Y%m%d%H%M)
   mv pos-app-backup-<timestamp> pos-app
   ```
3. Restore `public_html/js/`, `public_html/assets/`, `public_html/mix-manifest.json`, `public_html/index.php`, and `public_html/.htaccess` from the previous release.

## 4. Clear Caches, Re-Verify & Bring Online

```bash
cd ~/domains/staging.yourdomain.com/pos-app
php artisan optimize:clear
php artisan optimize
php artisan up
```

Verify `https://staging.yourdomain.com/` and `https://staging.yourdomain.com/app` return HTTP `200` and inspect `storage/logs/laravel-*.log`.
