# Hostinger Staging `.env` Template (Placeholders Only)

Create `~/domains/staging.yourdomain.com/pos-app/.env` (`chmod 600 .env`) using these values. Never commit real credentials.

```dotenv
APP_NAME="Digital Inventory POS (Staging)"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://staging.yourdomain.com

# Binds public_path() to public_html for CLI/Cron & zero-symlink uploads
PUBLIC_PATH=/home/<u_account>/domains/staging.yourdomain.com/public_html

LOG_CHANNEL=daily
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=warning

# Dedicated Staging MariaDB 10.5 Database (NEVER use production DB!)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<staging_db_name>
DB_USERNAME=<staging_db_user>
DB_PASSWORD=<staging_strong_password>

BROADCAST_DRIVER=log
CACHE_DRIVER=file
FILESYSTEM_DISK=public
MEDIA_DISK=public
PROTECTED_FILESYSTEM_DISK=local_protected
QUEUE_CONNECTION=database
SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_DOMAIN=staging.yourdomain.com
SESSION_SECURE_COOKIE=true

SANCTUM_STATEFUL_DOMAINS=staging.yourdomain.com
SANCTUM_TTL=1440

# Mail (use Hostinger SMTP or Mailtrap sandbox for staging)
MAIL_MAILER=smtp
MAIL_HOST=<smtp_host_or_sandbox>
MAIL_PORT=465
MAIL_USERNAME=<staging_mail_user>
MAIL_PASSWORD=<staging_mail_password>
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="staging-noreply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# MUST stay false
UPGRADE_MODE=false

# Payment Gateways — USE SANDBOX / TEST KEYS ONLY ON STAGING
STRIPE_KEY=<stripe_test_pk>
STRIPE_SECRET_KEY=<stripe_test_sk>

PAYPAL_CLIENT_ID=<paypal_sandbox_client_id>
PAYPAL_SECRET=<paypal_sandbox_secret>
PAYPAL_MODE=sandbox

RAZOR_KEY=<razorpay_test_key>
RAZOR_SECRET=<razorpay_test_secret>
MIX_RAZOR_KEY="${RAZOR_KEY}"
```
