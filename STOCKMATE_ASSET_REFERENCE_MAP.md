# StockMate Asset Reference Map

## 1. Logo Assets

| Asset Path | Dimensions / Format | Used By |
|---|---|---|
| `public/images/logo/stockmate-logo.webp` | `500 × 166` WebP (alpha) | `getAppLogoUrl()`, `SadminSetting::getLogoAttribute()`, `Setting::getLogoAttribute()`, Landing header & footer (`web/layouts/header.blade.php`, `web/layouts/footer.blade.php`), Login/Register/Forgot/Reset screens (`/api/front-cms` -> `app_logo`), Super Admin & Store Admin sidebars (`/api/front-setting` -> `app_logo` & `store_logo`), Email verification page (`web/email-verify.blade.php`), PDF invoices (`getStoreLogo()` -> `getAppLogoUrl()`) |
| `resources/images/logo/stockmate-logo.webp` | `500 × 166` WebP (alpha) | Source copy synced by `webpack.mix.js` (`mix.copyDirectory('resources/images', 'public/images')`) |
| `public/images/logo/store-mate-logo.webp` | `500 × 166` WebP (alpha) | Preserved original backup |

## 2. Favicon & PWA Icon Assets

| Asset Path | Dimensions / Format | Referenced In |
|---|---|---|
| `public/images/logo/stockmate-favicon.png` | `1000 × 1000` PNG (alpha) | `getAppFaviconUrl()`, `/api/front-cms` & `/api/front-setting` (`app_favicon`), `web/app.blade.php`, `web/layouts/app.blade.php`, `web/email-verify.blade.php`, `TabTitle.js` |
| `public/images/logo/fevicon.png` | `1000 × 1000` PNG (alpha) | Preserved original backup |
| `public/favicon.ico` | Multi-size `16/32/48` ICO | Browser default `/favicon.ico`, `web/app.blade.php`, `web/layouts/app.blade.php`, `public/manifest.json` |
| `public/favicon-16x16.png` | `16 × 16` PNG | `web/app.blade.php`, `web/layouts/app.blade.php` |
| `public/favicon-32x32.png` | `32 × 32` PNG | `web/app.blade.php`, `web/layouts/app.blade.php` |
| `public/favicon-48x48.png` | `48 × 48` PNG | Source frame for `favicon.ico` |
| `public/favicon.png` | `64 × 64` PNG | `TabTitle.js` fallback |
| `public/apple-touch-icon.png` | `180 × 180` PNG | `web/app.blade.php`, `web/layouts/app.blade.php`, `resources/pos/public/index.html` |
| `public/logo192.png` | `192 × 192` PNG | `public/manifest.json`, `resources/pos/public/manifest.json` |
| `public/logo512.png` | `512 × 512` PNG | `public/manifest.json`, `resources/pos/public/manifest.json` |
| `public/manifest.json` | JSON | `web/app.blade.php`, `web/layouts/app.blade.php` |
