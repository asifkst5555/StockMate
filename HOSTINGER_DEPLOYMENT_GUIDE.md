# Hostinger Business Hosting Deployment Guide

**Target Platform**: Hostinger Business Web Hosting (PHP `8.3.37`, Composer 2, SSH, MariaDB `10.5` / InnoDB, Cron Jobs, no symlinks, no Redis/Horizon, no persistent workers, no Docker).

---

## 1. Pre-Deployment Preparation (Local Machine)

1. Build the React production bundle locally (Hostinger shared hosting should not run Webpack builds):
   ```bash
   NODE_OPTIONS="--openssl-legacy-provider --max-old-space-size=4096" npm run prod
   ```
2. Verify all automated tests pass locally:
   ```bash
   php artisan test
   ```
3. Prepare deployment artifact excluding local-only files:
   - **Exclude**: `.env`, `node_modules/`, `.git/`, `docker/`, `docker-compose.local.yml`, `storage/logs/*.log`, `storage/framework/cache/*`, `storage/framework/sessions/*`, `storage/framework/views/*`, `PROJECT_AUDIT/`.
   - **Include**: `public/js/app.js`, `public/mix-manifest.json`, `public/assets/`, `public/images/`, `public/.htaccess`, `app/`, `bootstrap/`, `config/`, `database/`, `lang/`, `resources/`, `routes/`, `storage/` (empty writable subdirs), `artisan`, `composer.json`, `composer.lock`.

---

## 2. Hostinger hPanel Setup

1. **PHP Version**: In hPanel -> *Advanced -> PHP Configuration*, select **PHP 8.3** and verify extensions: `pdo_mysql`, `mbstring`, `exif`, `bcmath`, `gd`, `zip`, `intl`, `xml`, `fileinfo`, `openssl`, `curl`.
2. **Database**: In hPanel -> *Databases -> Management*, create a new MariaDB 10.5 database and dedicated user with strong password. Record the database name, username, and password (never commit them to git).
3. **SSL / HTTPS**: Enable Free SSL (Let's Encrypt) and Force HTTPS in hPanel.

---

## 3. Upload & Directory Placement (App Outside `public_html`)

See `HOSTINGER_DIRECTORY_STRUCTURE.md` for full tree.
1. Upload the Laravel application root to:
   ```
   /home/<u_account>/domains/<yourdomain.com>/pos-app/
   ```
2. Copy the contents of `pos-app/public/` into:
   ```
   /home/<u_account>/domains/<yourdomain.com>/public_html/
   ```
3. Update `public_html/index.php` bootstrap paths to point to `../pos-app/` and bind `public_path` (exact snippet in `HOSTINGER_DIRECTORY_STRUCTURE.md`).

---

## 4. Server Installation via SSH

```bash
cd ~/domains/<yourdomain.com>/pos-app

# 1. Install production PHP dependencies (IdeHelperServiceProvider is now safely dev-only)
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Create production .env from .env.example and fill real credentials securely
cp .env.example .env
nano .env   # Fill DB_*, APP_URL, PUBLIC_PATH, MAIL_*, SANCTUM_STATEFUL_DOMAINS

# 3. Generate a unique production APP_KEY
php artisan key:generate --force

# 4. Ensure writable directories (no symlinks required!)
chmod -R 775 storage bootstrap/cache
mkdir -p storage/app/private ~/domains/<yourdomain.com>/public_html/uploads
chmod -R 775 storage/app/private ~/domains/<yourdomain.com>/public_html/uploads

# 5. Inspect migration status BEFORE migrating (NEVER run migrate:fresh or db:wipe)
php artisan migrate:status
php artisan migrate --force

# 6. First-time empty database ONLY: run seeders once, then IMMEDIATELY change default passwords
# php artisan db:seed --force

# 7. Cache config, routes, and views for production performance
php artisan optimize
```

---

## 5. Post-Deployment Verification

1. Verify `https://<yourdomain.com>/` and `https://<yourdomain.com>/app` return HTTP `200`.
2. Hard-refresh a deep React route (e.g. `https://<yourdomain.com>/app/pos`) and confirm HTTP `200` (no 404).
3. Verify `https://<yourdomain.com>/.env` returns HTTP `403` (blocked by `.htaccess`).
4. Verify `https://<yourdomain.com>/upgrade-to-v1-2-0` returns HTTP `403`.
5. Log in as Super Admin and Store Admin, **immediately change default seeded passwords**, and test a product image upload (`/uploads/...`), POS checkout, PDF invoice download, and Excel export.
6. Configure the single Laravel Scheduler Cron Job in hPanel (see `HOSTINGER_CRON_CONFIGURATION.md`).
