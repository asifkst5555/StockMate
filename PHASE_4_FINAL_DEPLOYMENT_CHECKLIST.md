# Phase 4 — Final Hostinger Deployment Checklist

## 1. Production Build & Frontend Verification

| Check | Classification | Verified Result |
|---|---|---|
| Production Build (`npm run prod`) | **PASS** | `✔ Mix: Compiled successfully in 23.76s` (`public/js/app.js` = `7.2 MB` raw, **`1.60 MB` gzipped**) |
| Source Maps Exposure | **PASS** | **0** `.map` files in `public/`; zero external source map exposure |
| Development Code Excluded | **PASS** | `0` occurrences of `react.development.js` in `public/js/app.js` |
| Gzip Compression & Asset Caching | **PASS** | Added `mod_deflate` (reduces JS payload by ~78% to `1.60 MB`) and `mod_expires` (1-month JS/CSS/image caching) in `public/.htaccess` |
| Real Production URL Compatibility | **PASS** | `environment.js` uses `window.location.origin`; **0** hardcoded `localhost`/`127.0.0.1` URLs in compiled bundle |
| React Router Deep-Link Refresh | **PASS** | Verified `GET /app/pos` -> HTTP `200` via Laravel catch-all `/app/{any}` + `.htaccess` front-controller |
| Initial Bundle Size Optimization | **WARNING** | `public/js/app.js` is `7.2 MB` uncompressed (`1.60 MB` gzipped) because all React modules are eagerly bundled. Future enhancement: apply `React.lazy()` + `Suspense` route-level code splitting (not changed now to preserve stability) |

## 2. Local Automated Test Summary

- **PHPUnit (`php artisan test`)**: **14 passed, 0 failed (`0.40s`)** — **PASS**
- **Migrations (`php artisan migrate`)**: **128/128 passed** — **PASS**
- **Queue Dry Run (`queue:work database --stop-when-empty ...`)**: **Exit code 0** — **PASS**

## 3. Pre-Deployment Operational Checklist (Execute Manually on Hostinger)

### Before Deployment
- [ ] **Backup** existing production database via hPanel (*Databases -> Backups*) or `mysqldump` (if upgrading an existing DB).
- [ ] Confirm Hostinger PHP version is **8.3** with `pdo_mysql`, `mbstring`, `exif`, `bcmath`, `gd`, `zip`, `intl`, `xml`, `fileinfo`, `openssl`, `curl`.
- [ ] Upload Laravel app to `~/domains/<yourdomain.com>/pos-app/` (excluding `.env`, `node_modules/`, `docker/`, `PROJECT_AUDIT/`, `storage/logs/*`).
- [ ] Upload `public/` contents to `~/domains/<yourdomain.com>/public_html/` (preserving any existing `public_html/uploads/`).
- [ ] Update `public_html/index.php` paths to `../pos-app/` and add `$app->usePublicPath(__DIR__);`.

### On Hostinger via SSH
- [ ] `cd ~/domains/<yourdomain.com>/pos-app`
- [ ] `composer install --no-dev --optimize-autoloader --no-interaction`
- [ ] `cp .env.example .env && chmod 600 .env` — populate real MariaDB, `APP_URL`, `PUBLIC_PATH`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`, and SMTP values (`APP_DEBUG=false`, `UPGRADE_MODE=false`).
- [ ] `php artisan key:generate --force`
- [ ] `mkdir -p storage/app/private ~/domains/<yourdomain.com>/public_html/uploads && chmod -R 775 storage bootstrap/cache ~/domains/<yourdomain.com>/public_html/uploads`
- [ ] `php artisan down --retry=60`
- [ ] `php artisan migrate:status` (inspect pending migrations — **NEVER** run `migrate:fresh`, `db:wipe`, or `truncate`)
- [ ] `php artisan migrate --force`
- [ ] *(First-time EMPTY database ONLY)* `php artisan db:seed --force`
- [ ] `php artisan optimize && php artisan up`

### Post-Deployment Mandatory Verification
- [ ] **BLOCKER RESOLVED AT GO-LIVE**: Immediately change default seeded passwords (`superadmin@infy-pos.com` & `admin@infy-pos.com`) away from `123456`.
- [ ] Add the 1-minute Cron Job in hPanel: `/usr/bin/php8.3 /home/<u_account>/domains/<yourdomain.com>/pos-app/artisan schedule:run >> /dev/null 2>&1`.
- [ ] Verify HTTP `200` on `/`, `/app`, `/app/pos` (hard refresh), `/js/app.js` (check `Content-Encoding: gzip`).
- [ ] Verify HTTP `403` on `/.env` and `/upgrade-to-v1-2-0`, and HTTP `401` on `/api/cache-clear`.
- [ ] Monitor `storage/logs/laravel-*.log` and `storage/logs/queue-cron.log` for any PHP 8.3 deprecation or runtime issues.

## 4. Rollback Strategy (If Production Migration or Release Fails)

1. Put app into maintenance mode: `php artisan down`.
2. Restore the pre-deployment SQL backup via hPanel / `mysql < backup.sql` (do **not** rely on `migrate:rollback` for data-mutating migrations).
3. Restore previous `pos-app/` and `public_html/` release files (keeping `public_html/uploads/` intact).
4. Run `php artisan optimize:clear && php artisan optimize && php artisan up`.
