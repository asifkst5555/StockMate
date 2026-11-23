# StockMate Multi-Currency Configuration — Audit Report

**Date:** 2026-11-23  
**Project:** `/Users/macminim4/Projects/digital-inventory-with-pos`

---

## 1. Existing Currency Architecture

StockMate already implements a relational currency system without needing a duplicate currency engine:

- **Model & Table:** `App\Models\Currency` (`currencies` table: `id`, `name`, `code`, `symbol`, timestamps).
- **System Default Currency (Super Admin):** Stored in `sadmin_settings` as `admin_default_currency` (currency ID) and `admin_default_currency_symbol` (symbol string). Managed via `SadminSettingAPIController` / `SadminSettingRepository` and `CurrencyAPIController`.
- **Per-Tenant / Store Currency:** Stored in `settings` (`tenant_id`, `key = 'currency'`, `value = <currency_id>`) alongside `is_currency_right` (`0` = left prefix, `1` = right suffix).
- **Subscription Plans:** `plans.currency_id` references `currencies.id`.
- **Backend Formatting:** `app/helpers.php` (`getCurrencyCode()`, `currencyAlignment($amount)`) dynamically resolves the tenant's configured currency symbol.
- **Frontend Formatting:** `resources/pos/src/shared/sharedMethod.js` (`currencySymbolHandling(isRightside, currency, value, is_forment)`) dynamically formats all KPI widgets, product prices, sales, purchases, POS totals, taxes, discounts, expenses, and reports using `frontSetting.value.currency_symbol` and `allConfigData.is_currency_right`.
- **Exchange Rates:** The application does **not** perform automatic multi-currency conversion on historical records; changing a store's currency setting changes the display currency without silently rewriting historical transaction amounts. No exchange rate was invented.

---

## 2. Pre-Update Database State

- **`currencies` table:** Contained only 1 record: `id = 1` (`India`, `INR`, `₹`). Neither `USD` nor `BDT` existed.
- **`sadmin_settings`:** `admin_default_currency = 1`, `admin_default_currency_symbol = ₹`.
- **`settings` (local default tenant):** `currency = 1`.
- **`plans` (ID 1 — Default Plan, `price = 0`):** `currency_id = 1`.
- **Historical Financial Records:** Verified `sales = 0`, `purchases = 0`, `transactions = 0`.

---

## 3. Code Gaps Identified & Resolved

1. **`app/Repositories/AdminRepository.php` (line 308):** Previously assigned `Currency::first()->id` (which picked `INR` ID `1`) when provisioning new tenants. Updated to resolve `getSadminSettingValue('admin_default_currency') ?: Currency::where('code', 'USD')->value('id') ?: Currency::first()?->id`.
2. **`app/Models/Transaction.php` (line 47):** Hardcoded `'₹'` fallback when plan currency was null. Updated to `getSadminSettingValue('admin_default_currency_symbol') ?? '$'`.
3. **`database/seeders/DefaultUserSeeder.php` & `database/seeders/DefaultSettingSeeder.php`:** Previously seeded only `INR` (`₹`) and hardcoded `'currency' => 1` / `'admin_default_currency_symbol' => '₹'`. Updated to idempotently seed `USD` (`$`) as default and `BDT` (`৳`) as an additional available currency.
