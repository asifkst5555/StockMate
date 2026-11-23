# StockMate Branding Changelog

## 1. Brand Assets Created / Updated
- `public/images/logo/stockmate-logo.webp` & `resources/images/logo/stockmate-logo.webp` (copied from supplied `store-mate-logo.webp`; original kept as backup)
- `public/images/logo/stockmate-favicon.png` & `resources/images/logo/stockmate-favicon.png` (copied from supplied `fevicon.png`; original kept as backup)
- Generated standard favicon & PWA icons from supplied `fevicon.png` (1000×1000 alpha PNG):
  - `public/favicon-16x16.png` (16×16)
  - `public/favicon-32x32.png` (32×32)
  - `public/favicon-48x48.png` (48×48)
  - `public/favicon.png` (64×64)
  - `public/favicon.ico` & `resources/pos/public/favicon.ico` (multi-size ICO: 16×16, 32×32, 48×48)
  - `public/apple-touch-icon.png` (180×180)
  - `public/logo192.png` & `resources/pos/public/logo192.png` (192×192)
  - `public/logo512.png` & `resources/pos/public/logo512.png` (512×512)

## 2. Backend & Configuration Files Modified
1. `app/helpers.php` — Updated `getAppName()`, `getAppLogoUrl()`, and `getAppFaviconUrl()` defaults to `StockMate`, `images/logo/stockmate-logo.webp`, and `images/logo/stockmate-favicon.png`.
2. `app/Models/SadminSetting.php` — Updated `getLogoAttribute()` fallback to `images/logo/stockmate-logo.webp`.
3. `app/Models/Setting.php` — Updated `getLogoAttribute()` fallback to `images/logo/stockmate-logo.webp`.
4. `config/app.php` — Updated default `APP_NAME` fallback from `'Laravel'` to `'StockMate'`.
5. `.env` & `.env.example` — Set `APP_NAME=StockMate`.
6. `database/seeders/DefaultSettingSeeder.php` — Updated default `sadmin_settings` branding values (`app_name`, `company_name`, `footer`, `logo`, `testimonial_main_title`, `email`, `sender_name`, `mail_from_address`) to StockMate.
7. `database/seeders/DefaultFrontDataSeeder.php` — Updated FAQ #11 support email to `support@stockmate.app`.

## 3. Frontend, Blade & Manifest Files Modified
8. `resources/pos/src/shared/tab-title/TabTitle.js` — Updated fallback browser title to `StockMate` and fallback favicon to `/favicon.png`.
9. `public/manifest.json` & `resources/pos/public/manifest.json` — Updated `short_name` (`StockMate`), `name` (`StockMate - Inventory Management & POS`), `start_url` (`/app`), and icon entries.
10. `resources/pos/public/index.html` — Updated `<title>StockMate</title>`, meta description, apple-touch-icon, and splash-screen logo.
11. `resources/views/web/app.blade.php` — Added `favicon.ico`, `favicon-32x32.png`, `favicon-16x16.png`, `apple-touch-icon.png`, and `manifest.json` links.
12. `resources/views/web/layouts/app.blade.php` — Added `favicon.ico`, `favicon-32x32.png`, `favicon-16x16.png`, `apple-touch-icon.png`, and `manifest.json` links.
13. `resources/views/web/layouts/header.blade.php` & `resources/views/web/layouts/footer.blade.php` — Updated logo `alt` attributes to `StockMate`.
14. `public/js/app.js` & `public/mix-manifest.json` — Rebuilt via `npm run prod`.

## 4. Non-Destructive Local Database Updates (`digital_inventory_pos`)
Updated 8 default keys in `sadmin_settings` (`app_name`, `company_name`, `footer`, `logo`, `testimonial_main_title`, `email`, `sender_name`, `mail_from_address`) and `faqs` #11. Zero user accounts, tenant records, subscriptions, roles, permissions, or business transactions were modified or deleted.
