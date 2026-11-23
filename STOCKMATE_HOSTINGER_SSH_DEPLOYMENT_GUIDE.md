# StockMate — Hostinger SSH Deployment Guide (`stockmate.pnextmedia.com`)

**Target Subdomain:** `stockmate.pnextmedia.com`  
**Public Web Root (Subdomain Folder):** `/home/<hostinger-user>/domains/pnextmedia.com/public_html/stockmate`  
**Laravel App Directory (Outside Public Web Root):** `/home/<hostinger-user>/apps/stockmate`  
**GitHub Repository:** `https://github.com/asifkst5555/StockMate.git`  
**Branch:** `main`  
**PHP Version Required:** PHP `8.2` (matches local runtime PHP `8.2.36` / Laravel `10.49.0`)

> **Security Notice:** Never store production database passwords, `APP_KEY`, mail passwords, or SSH private keys in Git. Configure all secrets directly in the server's `.env` file over SSH.

---

## Step 1 — Connect to Hostinger via SSH

```bash
ssh -p 65002 <hostinger-user>@<hostinger-server-ip>
```

Verify PHP 8.2, Composer, and Git are available:

```bash
php -v
composer --version
git --version
```

---

## Step 2 — Clone Repository Outside `public_html`

```bash
mkdir -p ~/apps
cd ~/apps
git clone https://github.com/asifkst5555/StockMate.git stockmate
cd ~/apps/stockmate
```

*(If the repository is private, clone using a Hostinger deploy SSH key or GitHub fine-grained Personal Access Token via SSH/credential helper—never embed a token in `.git/config`.)*

---

## Step 3 — Install PHP Dependencies (Production)

```bash
cd ~/apps/stockmate
composer install --no-dev --optimize-autoloader
```

---

## Step 4 — Deploy Public Web Root & Compiled Assets

All compiled React assets (`public/js/app.js`, `public/mix-manifest.json`, `public/css/`, `public/fonts/`) and all `337` NorthStar Market USA WebP product/category/banner images (`public/uploads/northstar/`) are already tracked in Git—**no Node.js / `npm` build is required on Hostinger**.

Link or sync `public/` to `/public_html/stockmate`:

```bash
mkdir -p ~/domains/pnextmedia.com/public_html/stockmate
cp -a ~/apps/stockmate/public/. ~/domains/pnextmedia.com/public_html/stockmate/
```

Update `~/domains/pnextmedia.com/public_html/stockmate/index.php` so paths point to `~/apps/stockmate`:

```php
require '/home/<hostinger-user>/apps/stockmate/vendor/autoload.php';
$app = require_once '/home/<hostinger-user>/apps/stockmate/bootstrap/app.php';
```

---

## Step 5 — Configure Production `.env`

```bash
cd ~/apps/stockmate
cp .env.example .env
nano .env
```

Set the required production values:

```env
APP_NAME=StockMate
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://stockmate.pnextmedia.com
PUBLIC_PATH=/home/<hostinger-user>/domains/pnextmedia.com/public_html/stockmate

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<hostinger_db_name>
DB_USERNAME=<hostinger_db_user>
DB_PASSWORD=<hostinger_db_password>

FILESYSTEM_DISK=public
MEDIA_DISK=public
SESSION_DRIVER=file
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=stockmate.pnextmedia.com
UPGRADE_MODE=false
```

Generate the application key:

```bash
php artisan key:generate --force
```

---

## Step 6 — Storage Permissions & Public Storage Symlink

```bash
cd ~/apps/stockmate
chmod -R 775 storage bootstrap/cache
php artisan storage:link
```

If `public_html/stockmate` is separate from `~/apps/stockmate/public`, also create the symlink inside the subdomain web root:

```bash
ln -sfn ~/apps/stockmate/storage/app/public ~/domains/pnextmedia.com/public_html/stockmate/storage
```

---

## Step 7 — Database Import & Laravel Optimization

1. Create the MySQL database and user in Hostinger hPanel.
2. Upload your verified SQL export securely via SCP/SFTP (never via Git) and import:

```bash
mysql -u <hostinger_db_user> -p <hostinger_db_name> < /home/<hostinger-user>/stockmate_northstar_usa.sql
```

3. Run Laravel production caches:

```bash
cd ~/apps/stockmate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## Step 8 — HTTPS & Production Verification

1. Enable Free SSL (Let's Encrypt) for `stockmate.pnextmedia.com` in Hostinger hPanel and enforce HTTPS redirect.
2. Verify in browser:
   - `https://stockmate.pnextmedia.com/app/login` loads with StockMate Orange theme.
   - Login as `admin@stockmate.com` → verify all 3 stores (`NorthStar Market – Manhattan`, `Brooklyn`, `Queens`) appear in the Store Switcher.
   - Verify USD (`$`) pricing across Dashboard, POS, Products, Sales, and Purchases.
   - Verify product images load cleanly from `/uploads/northstar/products/*.webp` with zero `404` errors in DevTools.
   - Verify `https://stockmate.pnextmedia.com/upgrade` is blocked (`UPGRADE_MODE=false`).
