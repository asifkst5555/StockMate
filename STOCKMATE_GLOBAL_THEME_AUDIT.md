# StockMate Global Theme Audit

## 1. Audit Summary

Inspected the complete React 17 SPA (`resources/pos/src/`), compiled CSS/SCSS bundles (`resources/pos/src/assets/css/`), Blade landing pages (`resources/views/web/`), and landing Tailwind/CSS assets (`public/assets/`).

- **Total theme/style files inspected**: 58+ CSS/SCSS/Blade/JS files (329 legacy purple/violet/primary-blue class & hex references identified).
- **Primary legacy brand colors detected**:
  - React Admin/POS SPA: `#6571ff` / `#6571FF` (43 occurrences), `#e0e3ff` (9 occurrences), `#8635fd` (4 occurrences), `.btn-purple` / `.text-purple` / Bootstrap `--bs-primary`.
  - Landing Page (`/`): `#8635FD` (`tailwind-config.js` `primary`, 16 occurrences in `public/assets/scss/style.css`, 5 occurrences in `resources/views/web/home.blade.php`), `#F9F5FF` (`secondary`), `#E0DBFB` (`primary-100`), `#dac2fe` (`primary-light`), `#070127` (`black-900`), `ring-purple-500` / `hover:bg-purple-100` (`header.blade.php`).
  - Charts & Loaders: `TopProgressBar.js` (`#6571FF`), `DashboardChart.jsx` (blue/purple gradient `rgba(72,126,247)` -> `rgba(142,68,173)`), `ThisWeekSalePurchaseChart.js` & `LineChart.js` (cyan/blue/pink gradients), `TopCustomersChart.js` & `TopSellingProductChart.js` (ECharts pie palettes).

## 2. Semantic Colors Preserved (NOT Recolored)

- **Success / In Stock / Completed / Paid**: Green (`#0ac074`, `.bg-success`, `.badge-light-success`, `.text-success`).
- **Danger / Error / Out of Stock / Unpaid**: Red (`#f62947`, `#DC2626`, `.bg-danger`, `.badge-light-danger`, `.text-danger`).
- **Warning / Low Stock / Pending / Partial**: Amber (`#ffb821`, `.bg-warning`, `.badge-light-warning`, `.text-warning`).

## 3. Centralized Theme Architecture

1. Created **`resources/pos/src/assets/css/stockmate-theme.css`** (imported globally in `resources/pos/src/App.js` after LTR/RTL stylesheets) defining all `--sm-*` CSS variables and overriding Bootstrap primary tokens, sidebar, header, buttons, links, forms, pagination, tabs, dropdowns, switches, datepickers, react-select, POS active states, and tables.
2. Updated **charts & top progress bar** (`TopProgressBar.js`, `DashboardChart.jsx`, `ThisWeekSalePurchaseChart.js`, `LineChart.js`, `TopCustomersChart.js`, `TopSellingProductChart.js`) to the official StockMate chart palette (`#F47716`, `#092B48`, `#FDBA74`, `#94A3B8`).
3. Updated **Landing Page** (`public/assets/js/tailwind-config.js`, `public/assets/scss/style.css`, `resources/views/web/home.blade.php`, `resources/views/web/layouts/header.blade.php`) from purple (`#8635FD`) to StockMate Orange (`#F47716`) & Deep Navy (`#092B48`).
