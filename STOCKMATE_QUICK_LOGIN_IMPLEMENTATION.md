# StockMate — Local-Only Quick Login Implementation

## 1. Architecture & Security Design

Quick Login is implemented strictly server-side with **zero credentials embedded in frontend source code, HTML, or compiled JavaScript**.

### Backend Controls
1. **Conditional Route Registration (`routes/web.php`)**:
   - `POST /local-quick-login` (`name: local.quick-login`) is registered **only** when `app()->environment('local')` is true.
   - Outside `local` (staging, production, testing), the route does not exist (`Route::has('local.quick-login') === false`) and returns HTTP `404`.
   - Because it is registered in `routes/web.php`, it runs inside Laravel's `web` middleware stack (`StartSession` + `VerifyCsrfToken`).
2. **Defense-in-Depth Controller Guard (`app/Http/Controllers/API/AuthController.php::quickLogin`)**:
   - Explicitly checks `if (! app()->environment('local'))` and returns HTTP `404`.
   - Accepts **only** `role` in `['superadmin', 'admin']` (arbitrary user IDs or email addresses are rejected with HTTP `422`).
   - Resolves the predefined local account server-side, authenticates via `Auth::login($user)`, regenerates the session ID (`$request->session()->regenerate()`), sets `Session::put('auth', $user->id)`, creates a standard Sanctum Bearer token, and returns the exact same payload shape as `AuthController::login()`.
   - Logs usage via `Log::info('Local quick login authenticated.', ['quick_login_role' => ..., 'user_id' => ..., 'ip' => ...])` without ever logging passwords.
3. **Safe Capability Flag (`app/Http/Controllers/API/SettingAPIController.php::getFrontCms`)**:
   - Exposes `'local_quick_login_enabled' => app()->environment('local')` (boolean only; no emails, passwords, or IDs).
4. **CSRF Meta Tag (`resources/views/web/app.blade.php`)**:
   - Added `<meta name="csrf-token" content="{{ csrf_token() }}">` so the SPA sends `X-CSRF-TOKEN` on `POST /local-quick-login`.

### Frontend UI & Auth Flow
1. **`resources/pos/src/store/action/authAction.js`**:
   - Extracted shared `completeLogin()` helper used by both `loginAction` (normal login unchanged) and `quickLoginAction(role, navigate, setQuickLoading)`.
   - `quickLoginAction` posts `{ role }` with `withCredentials: true` and `X-CSRF-TOKEN` to `/local-quick-login`, then completes normal Redux/Cookie/Sanctum login and redirects Super Admin -> `/app/admin/dashboard` and Store Admin -> `/app/user/dashboard`.
2. **`resources/pos/src/components/auth/Login.js`**:
   - Renders the two responsive buttons (**Login as Super Admin** and **Login as Store Admin**) inside the existing login card **only** when `frontCms?.value?.local_quick_login_enabled === true`.
   - Preserves existing StockMate colors, spacing, typography, and normal email/password login form.

## 2. Files Modified
- `routes/web.php`
- `app/Http/Controllers/API/AuthController.php`
- `app/Http/Controllers/API/SettingAPIController.php`
- `resources/views/web/app.blade.php`
- `resources/pos/src/store/action/authAction.js`
- `resources/pos/src/components/auth/Login.js`
- `tests/Feature/SecurityAndPosAuditTest.php`
- `public/js/app.js` & `public/mix-manifest.json` (rebuilt via `npm run prod`)
