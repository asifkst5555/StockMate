# StockMate — Logout Bug Root Cause Audit

## 1. Observed Symptom & Actual Failed HTTP Response

After signing in via either Quick Login button (`Login as Super Admin` or `Login as Store Admin`) and clicking **Logout** in the header, the UI displayed `"Something Went wrong!"` (the error toast title from `resources/pos/src/locales/en.json:389` — `"toast.error.title": "Something Went wrong!"`) and the user remained logged in.

Reproducing the exact browser stateful request (`POST http://127.0.0.1:8000/api/logout` with `Origin: http://127.0.0.1:8000`, `Referer`, and session cookies) returned:

```http
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json

{"success":false,"message":"CSRF token mismatch."}
```

## 2. Root Causes Identified (4 Interlocking Defects)

1. **Missing `Authorization: Bearer` & Fresh CSRF Header in `logoutAction` (`resources/pos/src/store/action/authAction.js`)**:
   - `logoutAction` called `apiConfig.post("logout", token)`, where `apiConfig.js` initializes `axiosInterceptor.setupInterceptors(axiosApi, true, false)` — passing `isToken = true` causes `axiosInterceptor.js` to **skip** attaching the `Authorization: Bearer` header.
   - Because `127.0.0.1:8000` is in `SANCTUM_STATEFUL_DOMAINS`, Sanctum's `EnsureFrontendRequestsAreStateful` middleware activates `VerifyCsrfToken` on `/api/logout`. After Quick Login's `$request->session()->regenerate()`, `apiConfig.post("logout", token)` sent no `withCredentials` + no fresh `X-XSRF-TOKEN`, triggering `TokenMismatchException` (rendered as HTTP `422 "CSRF token mismatch."` by `Handler.php`).
2. **Infinite Recursion in `Multitenantable` Global Scope (`app/Traits/Multitenantable.php`)**:
   - When `Auth::login($user)` authenticated the `web` session guard during Quick Login, subsequent stateful requests caused `EloquentUserProvider::retrieveById()` to query `User`, which triggered `Multitenantable`'s `Auth::check()` -> re-invoking `Auth::guard('web')->user()` -> querying `User` infinitely until PHP aborted with an empty HTTP `500`!
   - Switching `Auth::check()` to `Auth::hasUser()` eliminates recursion because `hasUser()` checks only whether a user is already resolved in memory without querying the database.
3. **Incomplete Session & Token Revocation in `AuthController::logout()` (`app/Http/Controllers/API/AuthController.php`)**:
   - Previously did not call `Auth::guard('web')->logout()`, `$request->session()->invalidate()`, or `$request->session()->regenerateToken()`, and when authenticated via `web` session (`TransientToken`), did not revoke the `PersonalAccessToken` sent in the `Authorization: Bearer` header.
4. **Logout Blocked Behind `check_subscription` (`routes/api.php`)**:
   - `POST /api/logout` was nested inside `check_subscription`, which could prevent tenants with expired subscriptions from logging out.
