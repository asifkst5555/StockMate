# Hostinger Queue & Cron Configuration

Hostinger Business Web Hosting does **not** support persistent Supervisor queue workers, Redis, or Laravel Horizon. Instead, the application is configured to use the **database queue driver** (`QUEUE_CONNECTION=database`) processed by a short-running, overlap-protected Laravel Scheduler Cron Job.

## 1. Database Queue Migration

Added safe additive migration:
- `database/migrations/2026_11_23_000002_create_jobs_table.php` (creates `jobs` table if absent; complements existing `failed_jobs` table).

## 2. Configured Schedule (`app/Console/Kernel.php`)

```php
$schedule->command('queue:work database --stop-when-empty --max-time=50 --tries=3 --backoff=10')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->appendOutputTo(storage_path('logs/queue-cron.log'));

$schedule->command('queue:prune-failed --hours=168')
    ->dailyAt('02:30')
    ->withoutOverlapping(10);

$schedule->command('sanctum:prune-expired --hours=24')
    ->dailyAt('03:00')
    ->withoutOverlapping(10);
```

### Why This Is Safe on Hostinger
- **Short-running (`--stop-when-empty --max-time=50`)**: Exits cleanly within 50 seconds so it never hits Hostinger's PHP/cron execution timeout.
- **No overlapping (`->withoutOverlapping(2)`)**: Uses Laravel's file cache lock so two cron invocations cannot process the same queue simultaneously (preventing duplicate imports or duplicate emails).
- **Retry & failure logging (`--tries=3 --backoff=10`)**: Retries transient failures up to 3 times with 10-second backoff and records permanent failures in `failed_jobs` + `storage/logs/queue-cron.log`.
- **Transaction-safe POS & stock**: POS checkout (`SaleRepository::storeSale`), payment recording, stock deductions (`lockForUpdate()`), purchases, and transfers execute synchronously inside database transactions; non-critical notifications (mail/SMS) run after `DB::commit()`.

## 3. Hostinger hPanel Cron Job Entry

In **hPanel -> Advanced -> Cron Jobs**, create a single **Custom** cron job running every minute (`* * * * *`):

```bash
/usr/bin/php /home/<u_account>/domains/<yourdomain.com>/pos-app/artisan schedule:run >> /dev/null 2>&1
```

> Confirm the exact PHP 8.3 binary path on Hostinger via SSH (`which php` or `/usr/bin/php8.3`).

## 4. Monitoring & Troubleshooting via SSH

```bash
cd ~/domains/<yourdomain.com>/pos-app
php artisan schedule:list
php artisan queue:failed
tail -n 50 storage/logs/queue-cron.log
```
