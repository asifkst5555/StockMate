# StockMate Branding Audit

## 1. Supplied Brand Assets Inspection (`public/images/logo/`)

| File | Format | Dimensions | Transparency | Size | Status |
|---|---|---|---|---|---|
| `public/images/logo/store-mate-logo.webp` | WebP | `500 × 166` px (horizontal) | Yes (`hasAlpha: yes`) | 20 KB | Preserved as backup; copied to `stockmate-logo.webp` |
| `public/images/logo/fevicon.png` | PNG (8-bit colormap) | `1000 × 1000` px (square) | Yes (`hasAlpha: yes`) | 372 KB | Preserved as backup; copied to `stockmate-favicon.png` & resized to all standard favicon/PWA icons |

## 2. Classification of All Branding References Across the Codebase

### A. User-Facing Old Branding (Replaced with `StockMate`)
1. `app/helpers.php`: `getAppName()` fallback `'POS'` -> `'StockMate'`; `getAppLogoUrl()` fallback `images/logo.png` -> `images/logo/stockmate-logo.webp`; `getAppFaviconUrl()` fallback `images/infyom.png` -> `images/logo/stockmate-favicon.png`.
2. `app/Models/SadminSetting.php` & `app/Models/Setting.php`: fallback `getLogoAttribute()` `images/infyom.png` -> `images/logo/stockmate-logo.webp`.
3. `config/app.php`: default `'name' => env('APP_NAME', 'Laravel')` -> `'StockMate'`.
4. `.env` & `.env.example`: `APP_NAME=StockMate`.
5. `database/seeders/DefaultSettingSeeder.php`: `app_name` (`InfyPOS SaaS` -> `StockMate`), `company_name` (`infy-pos` -> `StockMate`), `footer` (`All rights reserved by InfyOm Technologies` -> `All rights reserved by StockMate`), `logo` (`images/logo.png` -> `images/logo/stockmate-logo.webp`), `testimonial_main_title` (`Customers Who Choose POS-SaaS` -> `Customers Who Choose StockMate`), default support/mail addresses (`support@stockmate.app`, `noreply@stockmate.app`).
6. `database/seeders/DefaultFrontDataSeeder.php`: FAQ #11 `[labs@infyom.in]` -> `support@stockmate.app`.
7. Local `digital_inventory_pos` DB (`sadmin_settings` & `faqs` #11): safely updated the 8 default branding rows + FAQ #11 without touching any users, tenants, plans, permissions, or business transactions.
8. `resources/pos/src/shared/tab-title/TabTitle.js`: fallback title `"POS-Saas"` -> `"StockMate"`, fallback favicon -> `"/favicon.png"`.
9. `public/manifest.json`, `resources/pos/public/manifest.json`, `resources/pos/public/index.html`: `"React App"` / `"Create React App Sample"` / `"Metronic logo"` -> `"StockMate"` / `"StockMate - Inventory Management & POS"`.
10. `resources/views/web/app.blade.php`, `resources/views/web/layouts/app.blade.php`, `resources/views/web/layouts/header.blade.php`, `resources/views/web/layouts/footer.blade.php`: added standard favicon/apple-touch-icon/manifest tags and `"StockMate"` logo alt attributes.

### B. Technical Identifiers (Preserved Intact)
- PHP namespaces (`App\...`), class names, database tables, migrations, API routes (`/api/*`, `/api/sadmin/*`), Spatie permission names (`manage_pos_screen`, etc.), and existing seeded local user login emails (`admin@infy-pos.com`, `superadmin@infy-pos.com`) in `users` table (preserved so existing login accounts continue working without resetting credentials).
- `app/Repositories/AdminRepository.php` default walk-in customer/warehouse placeholder emails and `database/pos-saas.sql` legacy SQL dump (not executed; preserved).

### C. Third-Party / Vendor References (Preserved)
- `composer.json` packages (`infyomlabs/adminlte-templates`, `infyomlabs/laravel-generator`, `laravel/framework`, `spatie/*`, `stancl/tenancy`, etc.), `config/infyom/laravel_generator.php`, and `vendor/` / `node_modules/`.

### D. Design Preservation Confirmation
Zero changes were made to CSS/SCSS colors, gradients, themes, typography, sidebar layout, dashboard layout, navigation structure, button styles, icons, tables, forms, spacing, or POS layout.
