# Hostinger Production `.env` Configuration Guide

A sanitized, secret-free template has been written to `.env.example`. Do **not** commit real credentials to version control.

## Required Production Values

| Variable | Recommended Production Value | Notes |
|---|---|---|
| `APP_NAME` | `"Digital Inventory POS"` | Application display name |
| `APP_ENV` | `production` | Enables production optimizations & HTTPS URL forcing |
| `APP_KEY` | *(generate via `php artisan key:generate --force`)* | Unique 32-byte key on Hostinger; never reuse local key |
| `APP_DEBUG` | `false` | **Mandatory** — prevents stack trace & secret leakage |
| `APP_URL` | `https://yourdomain.com` | Exact HTTPS origin without trailing slash |
| `PUBLIC_PATH` | `/home/<u_account>/domains/<yourdomain.com>/public_html` | Binds `public_path()` for CLI/cron without symlinks |
| `LOG_CHANNEL` | `daily` | Prevents unbounded single log file growth |
| `LOG_LEVEL` | `warning` | Reduces disk I/O in production |
| `DB_CONNECTION` | `mysql` | Compatible with Hostinger MariaDB 10.5 |
| `DB_HOST` | `127.0.0.1` | Hostinger local MariaDB socket/host |
| `DB_PORT` | `3306` | Default MariaDB port |
| `DB_DATABASE` | `<hpanel_db_name>` | Created in Hostinger hPanel |
| `DB_USERNAME` | `<hpanel_db_user>` | Dedicated least-privilege DB user |
| `DB_PASSWORD` | `<strong_generated_password>` | Never reuse `root` |
| `CACHE_DRIVER` | `file` | No Redis on Hostinger Business Hosting |
| `SESSION_DRIVER` | `file` | File-backed sessions in `pos-app/storage/framework/sessions` |
| `SESSION_LIFETIME` | `120` | 2-hour session timeout |
| `SESSION_DOMAIN` | `.yourdomain.com` | Match your production domain |
| `SESSION_SECURE_COOKIE` | `true` | Cookies transmitted only over HTTPS |
| `SANCTUM_STATEFUL_DOMAINS` | `yourdomain.com,www.yourdomain.com` | Matches production SPA domain(s) |
| `SANCTUM_TTL` | `1440` | 24-hour API token expiration (minutes) |
| `QUEUE_CONNECTION` | `database` | Processed by 1-minute Cron Job (`jobs` table) |
| `FILESYSTEM_DISK` | `public` | Writes directly to `public_html/uploads` |
| `MEDIA_DISK` | `public` | Spatie MediaLibrary public disk |
| `PROTECTED_FILESYSTEM_DISK` | `local_protected` | Writes to `pos-app/storage/app/private` |
| `MAIL_MAILER` | `smtp` | Hostinger SMTP (`smtp.hostinger.com`, port `465`, `ssl`) |
| `UPGRADE_MODE` | `false` | **Must remain `false`** to keep `/upgrade-*` routes blocked |

## Security Reminders

- Set `.env` permissions to `chmod 600 .env`.
- Run `php artisan config:cache` after any `.env` edit.
- Store Stripe, PayPal, Razorpay, Paystack, and courier/SMS credentials exclusively in Hostinger's `.env` or encrypted settings — never in source files or documentation.
