# StockMate — Hostinger Production Deployment Report

**Deployment Date & Time:** 2026-11-23 21:51 (+06:00)  
**Production Domain:** `https://stockmate.pnextmedia.com`  
**GitHub Repository:** `https://github.com/asifkst5555/StockMate.git` (`git@github.com:asifkst5555/StockMate.git`)  
**Branch:** `main`  
**Deployed Commit Hash:** `93c8abb` (`93c8abb950c21be611bdc021070171a1d3f7bb28`)  
**Status:** **LIVE & VERIFIED**

---

## 1. Deployment Directory Architecture

- **Private Laravel Application Directory (outside `public_html`):** `/home/u776743488/stockmate_app`
- **Public Subdomain Document Root:** `/home/u776743488/domains/pnextmedia.com/public_html/stockmate`
- **Public Storage Symlink:** `/home/u776743488/domains/pnextmedia.com/public_html/stockmate/storage -> /home/u776743488/stockmate_app/storage/app/public`
- **Parent Website Protection:** `https://pnextmedia.com` (`/home/u776743488/domains/pnextmedia.com/public_html`) remained completely untouched (`HTTP 200`).

---

## 2. Runtime, Database & Migration Status

- **PHP Version:** `PHP 8.3.37` (CLI & web via Hostinger CloudLinux PHP Selector)
- **Laravel Version:** `Laravel Framework 10.49.0`
- **MySQL Database:** `u776743488_stockmate` (`11.8.9-MariaDB-log`, user `u776743488_stockmate_user`, host `localhost`)
- **Migration Status:** `0` pending migrations (all `129` migrations `[Ran]`; zero destructive commands executed)
- **Preserved Production Data:**
  - All `6` stores preserved (`NorthStar Market – Manhattan`, `NorthStar Market – Brooklyn`, `NorthStar Market – Queens`, plus `FreshMart Central`, `FreshMart Bheramara`, `FreshMart Mirpur`).
  - Existing admin accounts, roles, passwords, and tenant IDs preserved untouched.
  - Verified live `getTopSellingProducts()` returns **`10`** tenant-scoped ranked products for `admin@stockmate.com`.

---

## 3. Backup Verification

All backups are stored outside the public web root under `/home/u776743488/stockmate_backups/`:

- `pre_deploy_20261123/default.php` — Original Hostinger subdomain placeholder
- `stockmate_northstar_usa_export.sql.gz` (`chmod 600`) — Verified SQL export archive
- `pre_update_20261123_2150/` — Timestamped backup taken before deploying `93c8abb`:
  - `app.js` (`7.3M`)
  - `mix-manifest.json` (`6.0K`)
  - `DashboardAPIController.php` (`8.6K`)

---

## 4. Uploaded Image & Frontend Build Verification

- **Frontend Production Build (`npm run prod`):** **PASS (`0` errors)**
- **PHPUnit Suite (`php artisan test`):** **PASS (`39 passed, 0 failed` in `1.87s`)**
- **Deployed Compiled Assets (verified via SHA-256 checksum match):**
  - `public_html/stockmate/js/app.js` (`7,643,949` bytes, SHA-256 `fe9de3d7f5c45a37c598c23446365eaadfa65fae92a96350e7008a5376846804`)
  - `public_html/stockmate/mix-manifest.json` (`6,105` bytes, SHA-256 `4a9692e84e5628d67deb7b820bdc7e44b0f22ae030ceeb0aaad817b1bcc070d0`)
- **NorthStar Market USA WebP Assets in Public Root (`337 / 337` verified):**
  - Primary product images: `159 / 159`
  - Product thumbnails: `159 / 159`
  - Category images: `14 / 14`
  - Dashboard banners: `5 / 5`
- **Included Dashboard UI/UX Updates Confirmed in Live `app.js` Bundle:**
  - `stockmate-dashboard-split` (`47% / 53%` desktop grid + full-height Sales & Purchases chart)
  - `stockmate-quantity-badge` (high-contrast `#FFF1E6` / `#092B48` Top 10 Products quantity badges)
  - `fm-promo-tile-icon` (four white Lucide-style SVG category icons: `leaf`, `shopping-basket`, `cup-soda`, `house`)
  - `rgba(9,43,72,.84)` (full-image deep navy hero overlay + full-card category overlays)

---

## 5. Production Smoke Tests & Security Verification

- **HTTPS & Routes:**
  - `https://stockmate.pnextmedia.com/` → `HTTP 200`
  - `https://stockmate.pnextmedia.com/app/login` → `HTTP 200`
  - `https://stockmate.pnextmedia.com/js/app.js` → `HTTP 200` (exact SHA-256 match with `93c8abb`)
  - Sample NorthStar hero, category, product, and thumbnail WebPs → `HTTP 200 image/webp`
  - `https://pnextmedia.com/` → `HTTP 200` (parent site untouched)
- **Security Checks:**
  - `APP_ENV=production`, `APP_DEBUG=false`, `UPGRADE_MODE=false`, `.env` permissions `600`, `storage/` & `bootstrap/cache/` permissions `775`
  - `/.env` → `HTTP 403`
  - `/composer.json` → `HTTP 403`
  - `/upgrade` → `HTTP 404`
  - Zero new Laravel runtime errors in `storage/logs/laravel.log`
- **Cache Status:** `optimize:clear`, `config:cache`, `route:cache`, and `view:cache` all succeeded.

---

## 6. Remaining Manual Verification Note

- Automated headless browser rendering is unavailable on the local Mac (Chrome/Playwright not installed). Please perform a final visual walkthrough in your browser at `https://stockmate.pnextmedia.com/app/login` (use a hard refresh `Cmd + Shift + R` / `Ctrl + Shift + R` to clear cached JS) to confirm the dashboard hero overlay, four category icons, Top 10 table badges, pie charts, POS, and store switcher across desktop and mobile viewports.
- **Security Advisory:** Because the database password was previously shared in chat, consider rotating the MySQL password in Hostinger hPanel and updating `/home/u776743488/stockmate_app/.env` + running `php artisan config:cache` when convenient.
