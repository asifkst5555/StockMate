# StockMate — Logout Fix Changelog

## Modified Files

1. **`app/Traits/Multitenantable.php`**
   - Replaced `Auth::check()` with `Auth::hasUser()` in the `tenant` global scope and `saving` hook to eliminate infinite recursion when Laravel's `web` session guard resolves the `User` model.
2. **`app/Http/Controllers/API/AuthController.php` (`logout(Request $request)`)**
   - Revokes the Bearer `PersonalAccessToken` via `Sanctum::$personalAccessTokenModel::findToken($request->bearerToken())` (even when authenticated via `web` session).
   - Revokes `currentAccessToken()` when deletable.
   - Calls `Auth::guard('web')->logout()`, `Session::forget('auth')`, `$request->session()->invalidate()`, and `$request->session()->regenerateToken()`.
3. **`routes/api.php`**
   - Moved `Route::post('logout', [AuthController::class, 'logout']);` directly under `middleware(['auth:sanctum', 'verified'])` (outside `check_subscription`) so every authenticated user can always log out.
4. **`resources/pos/src/store/action/authAction.js` (`logoutAction`)**
   - Sends `POST /api/logout` with `{}` JSON payload, `withCredentials: true`, `Authorization: Bearer <token>`, and fresh `X-XSRF-TOKEN` from `Cookies.get('XSRF-TOKEN')` (falling back to `<meta name="csrf-token">`).
   - Clears all auth keys from `localStorage` (including `Tokens.GET_PERMISSIONS` and `user_time`), removes `authToken` cookie, resets Redux auth & config state, and redirects via `window.location.replace("/app/login")` so browser Back navigation cannot restore dashboard state.
5. **`tests/Feature/SecurityAndPosAuditTest.php`**
   - Added `test_logout_revokes_bearer_token_and_blocks_subsequent_protected_requests`.
6. **`public/js/app.js` & `public/mix-manifest.json`**
   - Rebuilt cleanly with `npm run prod`.
