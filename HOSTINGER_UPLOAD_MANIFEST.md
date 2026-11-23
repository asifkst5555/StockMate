# Hostinger Staging Upload Manifest

> Note: This workspace is not initialized as a git repository (`.git` not present). This manifest was verified directly against the live workspace directory tree.

## 1. MUST Upload to `pos-app/` (Outside `public_html/`)

| Path | Description |
|---|---|
| `app/` | All Laravel controllers, models (including `StockMovement`, tenant-scoped models), repositories, `SecureFileController`, hardened `SupplierImport`, `Kernel.php`, `AppServiceProvider.php`, `helpers.php` |
| `bootstrap/app.php`, `bootstrap/cache/.gitignore` | Application bootstrap (do **not** upload local `bootstrap/cache/*.php` files) |
| `config/` | Hardened `app.php` (no dev IdeHelper), `filesystems.php` (`local_protected` -> `storage/app/private`), `queue.php` (`after_commit => true`), `database.php`, `sanctum.php`, `session.php`, etc. |
| `database/migrations/` | All **128** migrations (126 original + `2026_11_23_000001_add_tenant_isolation_and_stock_movements.php` + `2026_11_23_000002_create_jobs_table.php`) |
| `database/seeders/` | Seeders (run **only** on brand-new empty staging DB) |
| `lang/` | Localization files |
| `resources/views/` | Blade views (landing pages, English/Arabic PDF templates, Excel templates, emails) |
| `routes/` | `web.php`, `api.php`, `sadmin.php`, `upgrade.php`, `tenant.php`, `console.php`, `channels.php` |
| `storage/app/.gitignore`, `storage/countries/`, `storage/fonts/` | Static storage resources (PDF fonts, country JSON) — create empty `storage/app/private`, `storage/logs`, `storage/framework/{cache/data,sessions,views}` on server |
| `artisan`, `composer.json`, `composer.lock`, `.env.example` | Artisan CLI, locked production dependencies, sanitized env template |

## 2. MUST Upload to `public_html/` (Web Root)

| Path (from local `public/`) | Description |
|---|---|
| `public/.htaccess` | Hardened HTTPS redirect, security headers, gzip (`mod_deflate`), caching (`mod_expires`), dotfile/log/SQL/PHP-in-uploads blocking, SPA rewrite |
| `public/index.php` | **Update paths to `../pos-app/`** and add `$app->usePublicPath(__DIR__);` |
| `public/js/app.js` | Locally compiled production React bundle (`7.2 MB` raw / `1.60 MB` gzipped, zero localhost URLs, zero `.map` files) |
| `public/js/chunks/` | Webpack chunks |
| `public/mix-manifest.json` | Laravel Mix manifest |
| `public/assets/` | Compiled CSS, JS (`custom.js`), fonts, images |
| `public/images/`, `public/fonts/`, `public/barcode-images/`, `public/import_demo_files/` | Static public assets |
| `public/favicon.ico`, `public/logo192.png`, `public/logo512.png`, `public/manifest.json`, `public/robots.txt` | Web/PWA metadata |

## 3. MUST NOT Upload (Strictly Excluded)

| Excluded Path | Reason |
|---|---|
| `.env` | Contains local `APP_KEY` and local DB settings — create fresh `.env` on Hostinger |
| `vendor/` | Install fresh on Hostinger via `composer install --no-dev --optimize-autoloader` |
| `node_modules/` | Dev build dependencies (2,348 packages) — never needed on Hostinger |
| `public/storage` | Local symlink — Hostinger disables symlinks; use `public_html/uploads/` |
| `docker/`, `docker-compose.local.yml` | Local macOS Docker setup only |
| `storage/logs/*.log`, `storage/framework/sessions/*`, `storage/framework/cache/*`, `storage/framework/views/*` | Local runtime logs and caches |
| `tests/`, `phpunit.xml` | Dev/test suite (optional to omit in production) |
| `PROJECT_AUDIT/`, `HOSTINGER_*.md`, `PHASE_4_*.md`, `README.md` | Audit & deployment documentation |
| `.DS_Store`, `.git/`, `*.sql`, `*.bak` | OS metadata, VCS history, and database dumps |

## 4. Required Hostinger PHP 8.3 Extensions

Verify enabled in hPanel -> *PHP Configuration*:
`bcmath`, `ctype`, `curl`, `dom`, `exif`, `fileinfo`, `gd`, `iconv`, `intl`, `json`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.
