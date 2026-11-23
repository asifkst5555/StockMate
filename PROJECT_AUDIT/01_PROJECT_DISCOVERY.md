# Phase 1: Complete Project Discovery

## 1. Project Overview

- **Workspace Path**: `/Users/macminim4/Projects/digital-inventory-with-pos`
- **Project Name / Identity**: `e-pos-saas` (based on Laravel 10 + InfyPOS / InfyOm SaaS Inventory & Point of Sale architecture, `composer.json` version `1.3.2`)
- **Architecture**: Hybrid Laravel 10 REST API + React 17 Single Page Application (SPA) served under `/app` and `/app/*`, plus Blade-rendered SaaS marketing landing pages (`/`, `/faqs`, `/terms-and-conditions`, `/privacy-policy`, `/return-policy`), Blade PDF/Excel templates, and Super Admin API (`/api/sadmin/*`).

## 2. Technology Stack & Versions

| Layer | Technology | Declared / Detected Version |
|---|---|---|
| Backend Framework | Laravel (`laravel/framework`) | `^10.23` |
| PHP Requirement | `php` | `^8.1` |
| Installed PHP (Local macOS) | **Not installed** | None found in `PATH`, `/opt/homebrew`, `/usr/local`, or Herd |
| Composer (Local macOS) | **Not installed** | None found |
| Database Engine (Configured) | MySQL (`DB_CONNECTION=mysql`) | Not installed natively; Docker.app v29.8.2 present (daemon stopped); `sqlite3` CLI present |
| Frontend Framework | React (`react`, `react-dom`) | `^17.0.2` (Redux 4 + Redux Thunk + React Router v6 + React Bootstrap v5) |
| Frontend Build System | Laravel Mix (`laravel-mix`) | `^6.0.6` (`webpack.mix.js`, `webpack-rtl.config.js`) |
| Installed Node.js / NPM | Node.js / npm (via NVM) | Node `v24.22.0`, npm `11.19.1` |
| Pre-bundled Artifacts | `vendor/` & `public/js/app.js` | `vendor/` present; `node_modules/` absent; `public/js/app.js` (7.5 MB) pre-built |

## 3. Core Backend Packages (`composer.json`)

- **Authentication**: `laravel/sanctum` `^3.2` (Bearer token API auth, `SANCTUM_TTL=5000`)
- **Authorization**: `spatie/laravel-permission` `^5.8` (Roles: `superadmin`, `admin`, custom roles; granular `manage_*` permissions)
- **Multi-Tenancy**: `stancl/tenancy` `^3.8` (single-database tenancy via `tenant_id` column + custom `App\Traits\Multitenantable` global scope and `App\Http\Middleware\MultiTenantMiddleware`)
- **Repository Pattern**: `prettus/l5-repository` `^2.9` + `spatie/laravel-query-builder` `^5.1` + `spatie/laravel-json-api-paginate` `^1.12`
- **Media & Documents**: `spatie/laravel-medialibrary` `^10.7`, `barryvdh/laravel-dompdf` `^2.0`, `carlos-meneses/laravel-mpdf` `^2.1`, `maatwebsite/excel` `^3.1`, `picqer/php-barcode-generator` `^2.2`
- **Payment Gateways (SaaS Subscriptions)**: `stripe/stripe-php` `^16.4`, `srmklive/paypal` `^3.0`, `razorpay/razorpay` `^2.9`, `yabacon/paystack-php` `^2.2`
- **Env Editor**: `brotzka/laravel-dotenv-editor` `^2.2`
- **Dev Dependencies**: `phpunit/phpunit` `^9.5.10`, `nunomaduro/collision` `^6.3`, `spatie/laravel-ignition` `^2.0`, `barryvdh/laravel-ide-helper` `^2.13`, `laravel/sail` `^1.18`

## 4. Project Structure Summary

- **`app/`**:
  - `Http/Controllers/API/` (36 tenant API controllers), `Http/Controllers/Sadmin/` (13 Super Admin controllers), plus root controllers (`StoreAPIController`, `HomeController`, payment gateway controllers, `MailTemplateAPIController`, `BusinessInformationAPIController`)
  - `Models/` (65 Eloquent models), `Repositories/` (44 repositories), `Http/Requests/`, `Http/Resources/`, `Exports/`, `Imports/`, `Mail/`, `Notifications/`, `Traits/` (`Multitenantable`, `HasJsonResourcefulData`), `helpers.php` (406 lines)
- **`routes/`**: `web.php`, `api.php` (459 lines), `sadmin.php` (100 lines), `upgrade.php`, `tenant.php`, `channels.php`, `console.php`
- **`database/`**: 126 migrations, 11 seeders (`DatabaseSeeder` calls 9 seeders), 1 factory (`UserFactory.php`)
- **`resources/`**: React SPA in `resources/pos/src/` (components, frontend POS screen, Redux store, locales), Blade views in `resources/views/` (marketing pages, PDFs including Arabic `pdf/ar/`, Excel templates, emails)
- **`tests/`**: Only default Laravel stubs (`tests/Unit/ExampleTest.php`, `tests/Feature/ExampleTest.php`) — zero domain tests exist.

## 5. Detected Configuration & Compatibility Issues

1. **Missing Local Runtime Stack (CRITICAL BLOCKER for native execution)**:
   - macOS host has no `php`, `composer`, `mysql`/`mariadb`, or `brew` installed.
   - `Docker.app` (Docker CLI `29.8.2`) is installed, though its daemon is currently not running.
2. **Node.js v24 Compatibility Risk with Legacy Frontend Stack**:
   - `package.json` uses React 17, `laravel-mix` ^6.0.6, `react-scripts` ^4.0.3, `sass` ^1.49.7, `webpack` 5.
   - Node.js `v24.22.0` uses OpenSSL 3 and drops legacy Node APIs; building Mix/Webpack assets may require `NODE_OPTIONS=--openssl-legacy-provider` and `--legacy-peer-deps` during `npm install`.
3. **Hardcoded Port-Stripping Frontend API URL (CRITICAL LOCAL SETUP BUG)**:
   - `resources/pos/src/config/environment.js` sets `URL: window.location.protocol + '//' + window.location.hostname`.
   - This strips `:8000` when running on `http://127.0.0.1:8000`, causing all SPA API calls to fail against port 80.
4. **`.env` Identical to `.env.example`**:
   - Ships with a committed/shared `APP_KEY`, `APP_DEBUG=false`, `DB_DATABASE="laravel"`, `DB_USERNAME="root"`, `DB_PASSWORD="root"`.
5. **Missing `public/storage` Symlink**:
   - `public/storage` symlink does not exist yet (required for `MEDIA_DISK=public`, barcodes, PDFs, Excel exports, and product images).
