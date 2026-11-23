# FreshMart Grocery — Premium Visual UI Changelog

**Date:** 2026-11-23  
**Theme Preserved:** StockMate Orange (`#F47716`) & Deep Navy (`#092B48`)

---

## Files Added & Updated

1. **[freshmart-premium.css](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/assets/css/freshmart-premium.css)** *(Added, imported in [App.js](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/App.js))*:
   - Defined clean e-commerce card styles (`.fm-pos-card`, `.fm-pos-img-stage` with `object-fit: contain`), 2-line clamped product titles, stock/low-stock/out-of-stock pill badges, BDT price & pack-unit row, rounded-square table thumbnails (`.fm-list-thumb`, `.fm-cat-thumb`), studio Product Detail gallery (`.fm-detail-main-stage`, `.fm-detail-thumbs`), and responsive Dashboard promotional banners (`.fm-hero-banner`, `.fm-promo-grid`).
2. **[Product.js (POS Cards)](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/frontend/components/product/Product.js)** *(Updated)*:
   - Redesigned POS product cards with crisp white image stage (`object-fit: contain`, `loading="lazy"`, graceful `onError` fallback), controlled 2-line title, brand & SKU pill, clear BDT (`৳`) price, pack-size badge, and live Stock / Low-Stock / Out-of-Stock badges.
3. **[FreshMartPromoBanners.jsx](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/components/dashboard/FreshMartPromoBanners.jsx)** *(Added) &* **[Dashboard.js](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/components/dashboard/Dashboard.js)** *(Updated)*:
   - Added restrained, Chaldal/Shwapno-inspired promotional banner showcase: *FreshMart Grocery – Everyday Essentials* hero + *Fresh Deals*, *Rice, Grains & Cooking Essentials*, *Snacks & Beverages*, and *Household Essentials* spotlight cards. No hardcoded fake discounts or altered prices.
4. **[Product.js (Products Listing)](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/components/product/Product.js)** *(Updated)*:
   - Upgraded row thumbnails to lazy-loaded `48×48` rounded-square thumbnails using optimized WebP `thumbUrl` with graceful fallback.
5. **[ProductCategory.js](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/components/productCategory/ProductCategory.js)** *(Updated)*:
   - Upgraded category thumbnails to lazy-loaded `60×44` rounded category visuals with StockMate orange-tinted borders and graceful fallback.
6. **[ProductDetail.js](file:///Users/macminim4/Projects/digital-inventory-with-pos/resources/pos/src/components/product/ProductDetail.js)** *(Updated)*:
   - Replaced raw carousel image with a centered studio product stage (`max-width: 420px`, `height: 340px`, `object-fit: contain`) and interactive thumbnail selector when multiple images exist.
