# StockMate — GitHub Image Asset Verification Report

**Date:** 2026-11-23  
**Commit Audited:** `96a7e27` (`main`)

---

## 1. Tracked Image Asset Breakdown (`git ls-files`)

| Asset Category | Tracked File Count | Storage Path | Status |
|---|---:|---|---|
| **NorthStar USA Primary Product Images** | **`159`** | `public/uploads/northstar/products/*.webp` | **100% Tracked (159 / 159)** |
| **NorthStar USA Product Thumbnails (`320×320`)** | **`159`** | `public/uploads/northstar/products/thumbs/*.webp` | **100% Tracked (159 / 159)** |
| **NorthStar USA Category Images** | **`14`** | `public/uploads/northstar/categories/*.webp` | **100% Tracked (14 / 14)** |
| **NorthStar USA Dashboard Banners** | **`5`** | `public/uploads/northstar/banners/*.webp` | **100% Tracked (5 / 5)** |
| **NorthStar USA Subtotal** | **`337`** | `public/uploads/northstar/` (`8.26 MB`) | **Verified** |
| **StockMate Branding & Landing Page Images** | **`86`** | `public/images/`, `public/uploads/{feature_image,partner_img,service_icon,step_image,testimonial_image,why_choose_image}/` | **Verified** |
| **Additional Gallery / Demo / QA Visuals** | **`236`** | `public/uploads/freshmart/`, `resources/pos/src/assets/images/`, `docs/` | **Verified** |
| **Total Tracked Image Files** | **`659`** | — | **51.22 MB total** |

## 2. NorthStar Market USA 159-Product Mapping Confirmation

- **Confirmed:** All **`159` / `159`** NorthStar Market USA unique product mappings in `database/seeders/data/northstar_usa_catalog.json` and `App\Services\FreshMartImageCatalog` have corresponding tracked WebP primary images and thumbnails in Git (`0` missing, `0` ignored).
- **Storage & Symlink Verification:** All NorthStar Market and StockMate uploaded assets live directly under `public/uploads/` (served statically via `APP_URL/uploads/...` with zero dependency on local Mac directories). `public/storage` symlink is excluded from Git and recreated cleanly on Hostinger via `php artisan storage:link`.

## 3. File Size & Git LFS Audit

- **GitHub 100 MB Individual File Limit:** **PASS — 0 files exceed 100 MB.**
- **Largest Tracked Files:**
  1. `resources/fonts/Arial-Unicode-Italic.ttf` (`22.91 MB`)
  2. `resources/fonts/arial_unicode_ms_italic_c29cd25b5a41a214c1abcbfbd485c395.ttf` (`22.91 MB`)
  3. `resources/fonts/Arial-Unicode-MS.ttf` (`22.20 MB`)
  4. `resources/fonts/arial_unicode_ms_normal_bde3b6461929727e44c4eccb87b5a1df.ttf` (`22.20 MB`)
  5. `resources/fonts/arial_unicode_ms_bold_19b99058f54b1069a017e0fff3e5d135.ttf` (`17.73 MB`)
- **Git LFS Status:** **Not required** (largest file is `22.91 MB`, well below GitHub's `100 MB` limit; all `337` NorthStar WebP images total only `8.26 MB`).

## 4. Fresh Clone Verification

- **Status:** **PENDING GITHUB PUSH** (will be executed immediately into a temporary directory once `asifkst5555` authenticates and `git push -u origin main` completes).
