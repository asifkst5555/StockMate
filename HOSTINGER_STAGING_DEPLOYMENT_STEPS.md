# Hostinger Staging Deployment Steps (Manual Guide)

> **Scope**: Isolated **staging** subdomain (e.g., `https://staging.yourdomain.com`) on Hostinger Business Web Hosting (PHP `8.3.37`, MariaDB `10.5`, SSH, Cron Jobs). Do **NOT** point this at your live production domain or production database.

---

## Step 1: Create Staging Subdomain & Database in Hostinger hPanel

1. **Subdomain**: In hPanel -> *Domains -> Subdomains*, create `staging.yourdomain.com` (Hostinger creates `~/domains/yourdomain.com/public_html/staging/` or `~/domains/staging.yourdomain.com/public_html/` — verify the exact path via SSH).
2. **SSL**: In hPanel -> *Security -> SSL*, ensure HTTPS SSL is active for `staging.yourdomain.com`.
3. **PHP Version**: In hPanel -> *Advanced -> PHP Configuration*, confirm **PHP 8.3** is selected with extensions: `pdo_mysql`, `mbstring`, `exif`, `bcmath`, `gd`, `zip`, `intl`, `xml`, `fileinfo`, `openssl`, `curl`.
4. **Dedicated Staging Database**: In hPanel -> *Databases -> Management*, create a **brand-new** MariaDB 10.5 database (e.g., `u123456789_pos_staging`) and a dedicated database user with a strong password. Never reuse production database credentials.

---

## Step 2: Build & Package Locally (macOS)

From `/Users/macminim4/Projects/digital-inventory-with-pos`:

```bash
# 1. Build minified React production assets (already built; re-run if frontend changed)
NODE_OPTIONS="--openssl-legacy-provider --max-old-space-size=4096" npm run prod

# 2. Verify all 14 automated tests pass locally
docker exec digital_inventory_pos_app php artisan test

# 3. Create clean staging archives (excludes .env, vendor, node_modules, docker, logs, audit docs)
mkdir -p /tmp/pos-staging-release

tar --exclude='.env' \
    --exclude='node_modules' \
    --exclude='vendor' \
    --exclude='docker' \
    --exclude='docker-compose.local.yml' \
    --exclude='PROJECT_AUDIT' \
    --exclude='HOSTINGER_*.md' \
    --exclude='PHASE_4_*.md' \
    --exclude='.DS_Store' \
    --exclude='public' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/framework/cache/data/*' \
    --exclude='storage/framework/sessions/*' \
    --exclude='storage/framework/views/*' \
    -czf /tmp/pos-staging-release/pos-app-staging.tar.gz .

tar --exclude='storage' \
    --exclude='.DS_Store' \
    -czf /tmp/pos-staging-release/pos-public-staging.tar.gz -C public .
```

---

## Step 3: Upload Archives via SFTP / SCP to Hostinger

Assuming staging domain root is `~/domains/staging.yourdomain.com/`:

```bash
scp /tmp/pos-staging-release/pos-app-staging.tar.gz <ssh_user>@<hostinger_host>:~/domains/staging.yourdomain.com/
scp /tmp/pos-staging-release/pos-public-staging.tar.gz <ssh_user>@<hostinger_host>:~/domains/staging.yourdomain.com/
```

---

## Step 4: Extract & Configure Directory Layout via SSH

```bash
ssh <ssh_user>@<hostinger_host>
cd ~/domains/staging.yourdomain.com

# 1. Create private application directory OUTSIDE public_html
mkdir -p pos-app
tar -xzf pos-app-staging.tar.gz -C pos-app

# 2. Extract public assets INSIDE public_html
mkdir -p public_html
tar -xzf pos-public-staging.tar.gz -C public_html

# 3. Remove uploaded tarballs
rm -f pos-app-staging.tar.gz pos-public-staging.tar.gz
```

### Update `public_html/index.php`

Edit `~/domains/staging.yourdomain.com/public_html/index.php` so all 3 paths point to `../pos-app/` and bind `public_path()`:

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
$app->usePublicPath(__DIR__);

$kernel = $app->make(Kernel::class);

$response = $kernel->handle(
    $request = Request::capture()
)->send();

$kernel->terminate($request, $response);
```

### Verify Directory Permissions

```bash
cd ~/domains/staging.yourdomain.com
mkdir -p pos-app/storage/app/private \
         pos-app/storage/framework/cache/data \
         pos-app/storage/framework/sessions \
         pos-app/storage/framework/views \
         pos-app/storage/logs \
         public_html/uploads

chmod -R 775 pos-app/storage pos-app/bootstrap/cache public_html/uploads
```

| Path | Permission | Purpose |
|---|---|---|
| `pos-app/.env` | `600` | Production/staging secrets (owner read/write only) |
| `pos-app/storage/` | `775` | Logs, framework cache/sessions/views, `app/private` |
| `pos-app/bootstrap/cache/` | `775` | Laravel compiled config/route/service caches |
| `public_html/uploads/` | `775` | Direct public uploads (product images, barcodes — no symlinks) |

---

## Step 5: Install Production Dependencies & Configure `.env`

```bash
cd ~/domains/staging.yourdomain.com/pos-app

# 1. Verify exact PHP 8.3 binary path
which php8.3 || which php
php -v

# 2. Install production Composer dependencies (no dev packages, optimized autoloader)
composer install --no-dev --optimize-autoloader --no-interaction

# 3. Create .env from template (see HOSTINGER_STAGING_ENV_TEMPLATE.md)
cp .env.example .env
chmod 600 .env
nano .env   # Fill staging DB_*, APP_URL, PUBLIC_PATH, SANCTUM_STATEFUL_DOMAINS, MAIL_*

# 4. Generate unique staging APP_KEY
php artisan key:generate --force
```

---

## Step 6: Run Staging Migrations & Initial Seeding (Dedicated Staging DB ONLY)

```bash
cd ~/domains/staging.yourdomain.com/pos-app

# 1. Confirm DB connection & migration status first (NEVER run migrate:fresh or db:wipe)
php artisan migrate:status

# 2. Run all 128 migrations on the dedicated staging database
php artisan migrate --force

# 3. On a BRAND-NEW empty staging database ONLY, seed initial roles, permissions, plans & admin
php artisan db:seed --force
```

### Immediately Replace Default Seeded Passwords

Log in via Tinker (or UI immediately after boot) to replace the seeded `123456` passwords on `superadmin@infy-pos.com` and `admin@infy-pos.com`:

```bash
php artisan tinker --execute="
\$sa = App\Models\User::where('email', 'superadmin@infy-pos.com')->first();
if (\$sa) { \$sa->password = Illuminate\Support\Facades\Hash::make('REPLACE_WITH_STRONG_SUPERADMIN_PASSWORD'); \$sa->save(); }
\$ad = App\Models\User::where('email', 'admin@infy-pos.com')->first();
if (\$ad) { \$ad->password = Illuminate\Support\Facades\Hash::make('REPLACE_WITH_STRONG_ADMIN_PASSWORD'); \$ad->save(); }
echo 'Admin passwords updated.' . PHP_EOL;
"
```

---

## Step 7: Optimize Laravel Caches

```bash
cd ~/domains/staging.yourdomain.com/pos-app
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

---

## Step 8: Configure Hostinger Staging Cron Job

1. Confirm the verified PHP 8.3 binary path from Step 5 (e.g., `/usr/bin/php8.3` or `/usr/bin/php`).
2. In **hPanel -> Advanced -> Cron Jobs**, add **one** Custom Cron Job (`* * * * *` — every minute):

```bash
/usr/bin/php8.3 /home/<u_account>/domains/staging.yourdomain.com/pos-app/artisan schedule:run >> /dev/null 2>&1
```

This single cron entry drives all 3 tasks in `app/Console/Kernel.php`:
- **Every minute**: `queue:work database --stop-when-empty --max-time=50 --timeout=45 --tries=3 --backoff=10` (`withoutOverlapping(2)`)
- **Daily `02:30`**: `queue:prune-failed --hours=168`
- **Daily `03:00`**: `sanctum:prune-expired --hours=24`

Verify via SSH:
```bash
php artisan schedule:list
```

---

## Step 9: Execute Staging Smoke Tests

Follow every item in `HOSTINGER_STAGING_SMOKE_TEST.md` before considering production promotion.
