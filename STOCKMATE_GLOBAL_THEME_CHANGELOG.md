# StockMate Global Orange Brand Rebranding — Changelog

**Date:** 2026-11-23  
**Project:** `digital-inventory-with-pos` (StockMate Inventory & POS Management SaaS)  
**Scope:** Global UI Theme Rebranding (Legacy Purple/Blue `#6571FF` / `#8635FD` → Official StockMate Orange `#F47716` & Deep Navy `#092B48`)

---

## 1. Summary of Brand Token System

Introduced a centralized StockMate design-token stylesheet loaded last in both LTR and RTL modes so every page inherits consistent brand styling without altering business logic, routes, database records, or semantic colors.

| Token | Value | Usage |
|---|---|---|
| `--sm-primary` | `#F47716` | Primary buttons, active navigation, links, focus rings, active tabs, POS primary CTA |
| `--sm-primary-hover` | `#E9680B` | Hover/active state for primary interactive controls |
| `--sm-primary-gradient` | `linear-gradient(135deg, #F47716, #FF8A00)` | Primary KPI cards, POS header button, Pay Now button, auth CTAs |
| `--sm-primary-light` | `#FFF4E8` | Active sidebar item background, light primary badges, dropdown hover |
| `--sm-primary-border` | `#FED7AA` | Soft orange borders & secondary highlights |
| `--sm-navy` | `#092B48` | Deep Navy KPI cards, user avatars, POS utility toolbar buttons, headings |
| `--sm-navy-hover` | `#123B5D` | Navy gradient end / hover state |
| `--sm-navy-light` | `#EAF1F7` | Secondary button hover background |
| `--sm-background` | `#F8FAFC` | Application canvas background |
| `--sm-surface` | `#FFFFFF` | Card and modal surfaces |
| `--sm-text` / `--sm-muted` | `#17212B` / `#64748B` | Primary and secondary readable typography |

Semantic colors (**Green** `#059669`/`#10B981` for success/received/in-stock, **Red** `#DC2626`/`#EF4444` for danger/expense/reset, **Amber** `#D97706`/`#F59E0B` for warning/pending) are strictly preserved.

---

## 2. Files Created

1. **`STOCKMATE_GLOBAL_THEME_AUDIT.md`**  
   Complete pre-implementation audit cataloging all legacy purple/blue hex occurrences (`#6571FF`, `#8635FD`, `#E0E3FF`) across CSS, React charts, loaders, and Blade landing templates.
2. **`resources/pos/src/assets/css/stockmate-theme.css`**  
   Centralized StockMate global stylesheet overriding Bootstrap 5 `--bs-primary`, buttons (excluding `.bg-transparent` dropdown toggles), sidebar active borders/icons/submenus, header active tab underlines, POS header circle & quick-shortcut `+` buttons, Deep Navy user avatars, forms/focus rings/checkboxes/datepickers, pagination, tables, loaders, dashboard KPI widget gradients (replacing neon purple/magenta gradients with Orange/Navy/Semantic gradients), POS toolbar/category/cart/calculator controls, and auth pages.

---

## 3. Files Modified

### React Application Core & Loaders
- **`resources/pos/src/App.js`**  
  Imported `resources/pos/src/assets/css/stockmate-theme.css` at the end of `selectCSS()` so it applies consistently to both LTR and RTL views after `custom.css`, `style.css`, and `frontend.css`.
- **`resources/pos/src/shared/components/loaders/TopProgressBar.js`**  
  Updated top progress bar gradient/color from `#6571FF` to `#F47716` / `#FF8A00`.

### Charts & Data Visualizations (All 5 Chart Components)
- **`resources/pos/src/components/admin/dashboard/DashboardChart.jsx`** — Super Admin earnings bar/line series updated to StockMate Orange (`#F47716` / `#FF8A00`).
- **`resources/pos/src/components/dashboard/ThisWeekSalePurchaseChart.js`** — Sales series set to StockMate Orange (`#F47716`) and Purchases series set to Deep Navy (`#092B48`).
- **`resources/pos/src/components/dashboard/LineChart.js`** — Primary line series updated to StockMate Orange (`#F47716`) and secondary series to Deep Navy (`#092B48`).
- **`resources/pos/src/components/dashboard/TopCustomersChart.js`** — Pie/donut palette updated to StockMate Orange, Deep Navy, Warm Orange (`#FF8A00`), Slate Navy (`#123B5D`), Soft Peach (`#FDBA74`), and Slate (`#94A3B8`).
- **`resources/pos/src/components/dashboard/TopSellingProductChart.js`** — ECharts pie palette updated to the same StockMate Orange + Deep Navy color scale.

### Public Landing Page & Blade Templates
- **`public/assets/js/tailwind-config.js`** — Updated Tailwind theme tokens (`primary: "#F47716"`, `secondary: "#FFF4E8"`, `black-900: "#092B48"`, `primary-100: "#FED7AA"`, `primary-light: "#FDBA74"`, `shadow-1`).
- **`public/assets/scss/style.css`** — Replaced all 16 legacy `#8635fd` occurrences with `#F47716`.
- **`resources/views/web/home.blade.php`** — Replaced all legacy `#8635fd` inline color occurrences with `#F47716`.
- **`resources/views/web/layouts/header.blade.php`** — Replaced `ring-purple-500` → `ring-orange-500` and `hover:bg-purple-100` → `hover:bg-orange-50`.

### Compiled Production Assets
- **`public/js/app.js`**, **`public/mix-manifest.json`** — Recompiled cleanly via `npm run prod` (Laravel Mix / Webpack production build).

---

## 4. Non-Regression Guarantees
- **Unchanged:** V2 Login page (`Login.js`, `Login.css`), StockMate logo/favicon assets, Laravel routes/controllers/models/migrations, database records, Quick Login, Logout, Sanctum/CSRF auth, multi-tenant isolation, and Hostinger deployment files.
