# Hostinger Directory Structure & Security Isolation

Hostinger Business Web Hosting serves websites from `public_html/` without allowing custom document roots to `public/`. To prevent exposing `.env`, `vendor/`, `storage/`, and source code, place the Laravel application **outside** `public_html/`.

## 1. Target Server Layout

```text
/home/<u_account>/domains/<yourdomain.com>/
├── pos-app/                         # PRIVATE — NOT web-accessible
│   ├── app/
│   ├── bootstrap/
│   ├── config/
│   ├── database/
│   ├── lang/
│   ├── resources/
│   ├── routes/
│   ├── storage/
│   │   ├── app/
│   │   │   └── private/             # Private tenant files (local_protected disk)
│   │   ├── framework/
│   │   └── logs/
│   ├── vendor/
│   ├── .env                         # Production secrets (chmod 600)
│   ├── artisan
│   └── composer.json
│
└── public_html/                     # PUBLIC WEB ROOT (contents of local public/)
    ├── .htaccess                    # Hardened Apache/LiteSpeed rules
    ├── index.php                    # Adjusted to bootstrap ../pos-app
    ├── mix-manifest.json
    ├── js/
    │   └── app.js                   # Production React SPA bundle
    ├── assets/
    ├── images/
    ├── fonts/
    └── uploads/                     # Direct public uploads (NO symlink required!)
```

## 2. Modified `public_html/index.php`

Replace the three relative paths in `public_html/index.php` to point to `../pos-app`:

```php
<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/../pos-app/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../pos-app/vendor/autoload.php';

$app = require_once __DIR__.'/../pos-app/bootstrap/app.php';

// Bind public_path() to public_html so uploads work without symlinks
$app->usePublicPath(__DIR__);

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
```

> **Note**: `AppServiceProvider::register()` also honors `PUBLIC_PATH` from `.env` so Artisan CLI / Cron commands (which do not pass through `public_html/index.php`) resolve `public_path()` to `public_html/` identically.

## 3. Hardened `public_html/.htaccess`

Already updated in `public/.htaccess` to:
- Disable directory listing (`Options -MultiViews -Indexes`).
- Redirect HTTP -> HTTPS in production (skipping localhost).
- Block direct access to `.env`, `.git`, `.htaccess`, `composer.json`, `composer.lock`, `package.json`, `phpunit.xml`, `artisan`, `.log`, `.sql`, `.bak`, `.sh`, `.md`.
- Block PHP script execution inside `uploads/` (`^uploads/.*\.(php|phtml|php[3-8]|phar)$`).
- Pass `Authorization` header to PHP (required for Sanctum Bearer tokens).
- Route `/app/*` deep links cleanly through `index.php` so React SPA refreshes never 404.
- Send security headers (`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`).
