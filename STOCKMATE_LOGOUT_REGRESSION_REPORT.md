# StockMate — Logout Regression Test Report

## 1. Automated PHPUnit Suite (`php artisan test`)

**Result: 17 passed, 0 failed (`0.64s`)**

- All 16 previous security/POS/inventory/auth tests continue passing.
- New test `test_logout_revokes_bearer_token_and_blocks_subsequent_protected_requests` verifies login -> protected API `200` -> logout `200` -> `PersonalAccessToken::findToken($token)` is `null`.

## 2. Live Browser-Stateful Regression Matrix (`http://127.0.0.1:8000`)

Executed with full browser cookie jar (`stockmate_session` + `XSRF-TOKEN`), `Origin: http://127.0.0.1:8000`, `Referer`, `X-XSRF-TOKEN`, and `Authorization: Bearer`:

| # | Scenario | Login | Pre-Logout Dashboard | Logout | Post-Logout Old Bearer | Post-Logout Old Session Cookie | Re-Login After Logout | Status |
|---|---|---|---|---|---|---|---|---|
| 1 | **Super Admin Quick Login → Logout** | `200` | `/api/sadmin/dashboard` -> `200` | `200` | `401` | `401` | `200` | **PASS** |
| 2 | **Store Admin Quick Login → Logout** | `200` | `/api/today-sales-purchases-count` -> `200` | `200` | `401` | `401` | `200` | **PASS** |
| 3 | **Super Admin Normal Login → Logout** | `200` | `/api/sadmin/dashboard` -> `200` | `200` | `401` | `401` | `200` | **PASS** |
| 4 | **Store Admin Normal Login → Logout** | `200` | `/api/today-sales-purchases-count` -> `200` | `200` | `401` | `401` | `200` | **PASS** |

## 3. Additional Security & Frontend Checks

- **React Production Build (`npm run prod`)**: **PASS** (exit code 0).
- **Browser Back Navigation Prevention**: `logoutAction` uses `window.location.replace("/app/login")` and clears Redux `LOGIN_USER`, `FETCH_ALL_CONFIG`, `authToken` cookie, and all `localStorage` auth keys (`ADMIN`, `USER`, `GET_PERMISSIONS`, `user_time`, `loginUserArray`).
- **Local-Only Quick Login Security**: Quick Login remains strictly `APP_ENV=local`-only, CSRF-protected, and zero credentials appear in `public/js/app.js` or `resources/pos/src/`.
- **Design & Branding**: Zero UI/color/branding regressions.
