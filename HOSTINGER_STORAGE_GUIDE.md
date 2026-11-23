# Hostinger Storage Architecture (Zero Symlinks)

Hostinger Business Web Hosting disables PHP `symlink()`, so `php artisan storage:link` cannot be used.

## 1. How Public Files Work Without Symlinks

Inspected all `Storage::url()` and `Storage::disk()` references across `app/` and `config/filesystems.php`:
- Both `public` and `local` disks write directly to `public_path('uploads')` and generate URLs at `APP_URL/uploads/...`.
- By setting `PUBLIC_PATH=/home/<u_account>/domains/<yourdomain.com>/public_html` in `.env` and `$app->usePublicPath(__DIR__);` in `public_html/index.php`:
  - `public_path('uploads')` resolves directly to `/home/<u_account>/domains/<yourdomain.com>/public_html/uploads/`.
  - Product images, brand/category images, store logos, favicons, barcodes (`uploads/product_barcode/...`, `uploads/sales/...`), and PDFs/Excel exports work natively with **zero symlinks**.
- `public/.htaccess` explicitly blocks PHP script execution inside `uploads/` (`^uploads/.*\.(php|phtml|php[3-8]|phar)$`).

## 2. Private Tenant Document Protection

Previously, `local_protected` in `config/filesystems.php` also pointed to `public_path('uploads')` (publicly readable). This has been fixed:
- `local_protected` now points to `storage_path('app/private')` (located inside `pos-app/storage/app/private/`, completely outside `public_html/`).
- Added authenticated, tenant-isolated streaming endpoint:
  - `GET /api/private-files/{path}` (`App\Http\Controllers\API\SecureFileController::download`)
  - Protected by `auth:sanctum`, `verified`, `check_subscription`.
  - Rejects path traversal (`..`) and enforces that `{path}` starts with the authenticated user's `{tenant_id}/` prefix.

## 3. Excel Export Tenant Isolation

- All Excel exports in `ReportAPIController` now write to tenant-prefixed directories (`excel/{tenant_id}/*.xlsx`) so tenants cannot overwrite each other's reports.
- Recommended future enhancement: move generated PDFs and Excel exports to `local_protected` and serve exclusively via `/api/private-files/{tenant_id}/...`.

## 4. Preserving Existing Uploads During Updates

- Never delete or overwrite `public_html/uploads/` or `pos-app/storage/app/private/` during deployments.
- Exclude `public/uploads/` from deployment zip/rsync transfers so existing customer product images and media are preserved.
