# FreshMart Grocery — Existing Image Asset & UI System Audit

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`  
**Demo Account:** `admin@stockmate.com` (ID `2`, 3 FreshMart Kushtia stores, `468` product records, `30` category records)

---

## 1. Existing Storage & Media Pipeline Audit

- **Media Library:** Spatie Media Library (`media` table) configured on `config('app.media_disc') = 'public'`.
- **Public Filesystem Root:** `public/uploads` mapped to `http://127.0.0.1:8000/uploads`.
- **Product Image Resolution:**
  - `MainProduct::getImageUrlAttribute()` reads `MainProduct::PATH` (`product_image`).
  - `Product::getImageUrlAttribute()` reads `Product::PATH` (`product_image`).
  - `ProductCategory::getImageUrlAttribute()` reads `ProductCategory::PATH` (`product_category`).
- **Pre-Upgrade State:** `0` of `468` FreshMart products and `0` of `30` categories had uploaded media, causing every POS product card, Products table row, Product Detail page, and Category row to render a generic placeholder icon (`NO IMAGE` / default logo).

## 2. Pre-Upgrade UI Gaps Identified

1. **POS Product Cards (`resources/pos/src/frontend/components/product/Product.js`):** Displayed raw stretched `NO IMAGE` placeholders, overlapping floating badges, no lazy loading, and no low-stock / out-of-stock visual differentiation.
2. **Products Table & Category List:** Circular `50×50` thumbnails clipped rectangular packaging and lacked graceful fallback handlers.
3. **Product Detail View (`ProductDetail.js`):** Displayed a large blank placeholder with no framed studio stage or thumbnail selector.
4. **Dashboard (`Dashboard.js`):** Contained KPIs and charts only, without restrained grocery merchandising banners to anchor the client presentation.

## 3. Architecture Decision (Zero Duplicate Files, Zero Schema Changes)

- Store one shared, optimized WebP asset library under `public/uploads/freshmart/` (`products/`, `products/thumbs/`, `categories/`, `banners/`).
- Resolve matched local WebP paths via `App\Services\FreshMartImageCatalog` only when a product or category has no user-uploaded Spatie media—preserving 100% of existing galleries, user upload workflows, product IDs, SKUs, barcodes, prices, costs, stock, and transactions.
