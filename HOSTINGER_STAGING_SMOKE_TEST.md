# Hostinger Staging Smoke-Test Checklist

Execute every check manually on `https://staging.yourdomain.com` after deployment. Record Pass/Fail and inspect `pos-app/storage/logs/laravel-*.log` after each section.

## 1. Infrastructure, Routing & Security
- [ ] **Landing Page**: `GET https://staging.yourdomain.com/` returns HTTP `200` over HTTPS; HTTP redirects `301` to HTTPS.
- [ ] **React SPA & Deep Refresh**: `GET https://staging.yourdomain.com/app` and hard-refreshing `https://staging.yourdomain.com/app/pos` return HTTP `200` with zero console errors.
- [ ] **Gzip Compression**: DevTools Network tab confirms `/js/app.js` is served with `Content-Encoding: gzip` (~1.6 MB transferred).
- [ ] **Blocked Files**: `/.env`, `/composer.json`, `/upgrade-to-v1-2-0` return HTTP `403`.
- [ ] **Unauthenticated API**: `/api/cache-clear` and `/api/stock-valuation` return HTTP `401`.
- [ ] **Cron & Queue**: Wait 2 minutes, then verify `pos-app/storage/logs/queue-cron.log` updates and `php artisan queue:failed` is empty.

## 2. Authentication & Dashboard
- [ ] **Login**: Log in at `/app` as Store Admin (with newly replaced strong password) and Super Admin; verify Sanctum token persists across page refresh.
- [ ] **Dashboard**: Verify KPI cards, charts, recent sales, and stock alerts load with HTTP `200` and zero SQL/PHP 8.3 errors in logs.

## 3. Inventory, Purchases & POS Workflows
- [ ] **Product CRUD & Image Upload**: Create a product with a PNG/JPG image -> verify image loads from `https://staging.yourdomain.com/uploads/...` (zero symlinks) -> edit -> delete a test product.
- [ ] **Purchase (`PENDING` vs `RECEIVED`)**: Create a `PENDING` purchase (verify stock does NOT increase), then create a `RECEIVED` purchase (verify stock increases and `GET /api/stock-movements` records a `purchase` row).
- [ ] **POS Sale (Cash Overpayment + Split Payment)**: Complete a POS checkout with cash overpayment (verify status = `PAID`, change calculated, stock deducted, `sale` row in `stock_movements`, receipt prints cleanly) and a split payment (Cash + Card).
- [ ] **Negative Stock Prevention**: Attempt to sell more than available stock -> verify HTTP `422` rejection and stock unchanged.
- [ ] **Stock Transfer & Adjustment**: Transfer stock between two warehouses (verify source decreases, destination increases); verify same-warehouse transfer is rejected.
- [ ] **Sales Return & Purchase Return**: Process a sales return (verify stock restored) and a purchase return (verify stock deducted).

## 4. Multi-Store & Tenant Isolation
- [ ] **Multi-Store Switching**: Create a second store for Admin A -> switch stores via UI -> verify data is isolated per store.
- [ ] **Cross-Tenant Block**: Create a second independent tenant (Admin B) -> verify Admin A cannot switch into Admin B's store (`403`), cannot view Admin B's purchases/sales/stock valuation (`GET /api/stock-valuation`), and cannot download Admin B's private files (`GET /api/private-files/...` -> `403`).

## 5. Documents, Exports & External Integrations
- [ ] **PDF Invoices**: Download a Sale PDF and Purchase PDF -> verify valid PDF renders cleanly (and Arabic PDF if used).
- [ ] **Excel Exports**: Export Stock Report and Sales Report -> verify file downloads from tenant-prefixed path `uploads/excel/{tenant_id}/...`.
- [ ] **Private File Upload/Download**: Verify a file placed in `pos-app/storage/app/private/{tenant_id}/test.pdf` is NOT accessible via public URL, IS downloadable via `/api/private-files/{tenant_id}/test.pdf` (`200`), and rejects traversal (`%2e%2e` -> `403`).
- [ ] **Courier / SMS Integrations (Sandbox)**: If configured, verify test webhook/SMS credentials in sandbox mode only; confirm failed external notification never rolls back a POS sale.
- [ ] **Payment Gateway Sandbox**: Test a subscription checkout using Stripe/PayPal/Razorpay **sandbox/test** keys only; confirm callback URL returns cleanly.

## 6. Log Check
- [ ] Inspect `pos-app/storage/logs/laravel-*.log` via SSH — confirm zero fatal errors or unhandled PHP 8.3 exceptions.
