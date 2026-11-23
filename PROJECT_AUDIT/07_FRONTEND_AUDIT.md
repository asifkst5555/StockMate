# Phase 7: Frontend and UI/UX Audit

## 1. Frontend Architecture

- **SPA Root**: `resources/views/web/app.blade.php` mounts `<div id="root"></div>` and loads `/js/app.js?t={{ time() }}`.
- **React App**: `resources/pos/src/` — React 17.0.2, Redux 4 + Redux Thunk + Redux Persist, React Router v6, React Bootstrap v5 (`react-bootstrap-v5` & `react-bootstrap` & `reactstrap`), `react-data-table-component`, `react-intl`, `react-to-print`, `echarts-for-react`, `chart.js`.
- **Build System**: Laravel Mix 6 (`webpack.mix.js`) compiles `resources/pos/src/index.js` -> `public/js/app.js` and `resources/assets/js/custom.js` -> `public/assets/js/custom/custom.js`.
- **Landing Pages**: Blade views under `resources/views/web/` (`home`, `faq`, `terms-conditions`, `privacy-policy`, `return-policy`, `email-verify`).

## 2. Verified Frontend Defects

1. **CRITICAL: API Base URL Strips Localhost Port** (`resources/pos/src/config/environment.js:2`):
   - Currently: `URL: window.location.protocol + '//' + window.location.hostname`
   - When served on `http://127.0.0.1:8000`, all API requests target `http://127.0.0.1/api/...` (port 80) and fail completely.
   - **Fix**: Use `window.location.origin` (or `window.location.protocol + '//' + window.location.host`).
2. **Malformed HTML Tag in SPA Entry View** (`resources/views/web/app.blade.php:17`):
   - Closing tag is `</html` (missing `>`).
   - Also line 14 uses `?t={{ time() }}` which disables browser caching on every page load (instead of `mix('/js/app.js')`).
3. **POS Decimal Discount Truncation** (`resources/pos/src/frontend/components/PosMainPage.js:578`):
   - `discount_value: parseInt(cartItemValue.discount_value)` truncates decimal percentages/amounts (e.g., `7.5` -> `7`). Should use `parseFloat(...) || 0`.
4. **POS Crash Before Payment Methods Load** (`resources/pos/src/frontend/components/PosMainPage.js:670, 718`):
   - `paymentValue.payment_type.label` and `paymentValue.payment_type.value` lack optional chaining (`?.`), throwing a `TypeError` when `paymentMethods` is initially empty.
5. **POS Premature State Reset & No Double-Submit Lock** (`resources/pos/src/frontend/components/PosMainPage.js:601-643`):
   - `onCashPayment` closes the modal and clears `cartItemValue` and `cashPaymentValue` synchronously before the API request completes, risking lost cart context on API error and duplicate checkout submissions.
6. **Webpack Alias Typo** (`webpack.mix.js:25`):
   - `'@': __dirname + 'resources'` is missing a path separator (`/`), resolving to `.../digital-inventory-with-posresources` instead of `.../resources`.
7. **Duplicate `mix.js` Target** (`webpack.mix.js:33, 35`):
   - Line 33 compiles `resources/js/app.js` to `public/js`, and line 35 overwrites `public/js/app.js` with `resources/pos/src/index.js`.
8. **Node.js v24 / Legacy Dependency Conflicts**:
   - `package.json` includes `react-scripts: ^4.0.3`, `laravel-mix: ^6.0.6`, `sass: ^1.49.7`, `react: ^17.0.2`, and unused/redundant UI libraries (`react-bootstrap`, `react-bootstrap-v5`, `reactstrap`, `fortawesome` placeholder package). `node_modules/` is currently not installed.

## 3. Pages & Modules Inventory (Verified in `resources/pos/src/components/`)

- Dashboard, Sidebar, Header, Footer, Auth (Login/Register/Forgot/Reset), User Profile
- Products, Main Products, Variations, Product Categories, Brands, Units, Base Units, Print Barcode
- Warehouses, Stores, Transfers, Adjustments
- Suppliers, Purchases, Purchase Returns
- Customers, Quotations, Sales, Sale Returns, POS Screen, POS Register, Customer Dual-Screen Display
- Expenses, Expense Categories, Payment Methods, Taxes, Currencies, Languages, Mail/SMS Templates, Settings, Roles, Users, Subscriptions, Super Admin CMS
