# Phase 4 — Queue, Cron & Import Verification

## 1. Queue & Scheduler Architecture Audit

| Check | Classification | Verified Details |
|---|---|---|
| Queue Driver (`config/queue.php`) | **PASS** | `QUEUE_CONNECTION=database` using `jobs` table (`2026_11_23_000002_create_jobs_table.php`) and `failed_jobs` table (`database-uuids`) |
| Transaction-Safe Job Dispatch | **PASS** | Set `'after_commit' => true` on `database` queue connection so jobs dispatched inside DB transactions are never picked up before commit or after rollback |
| Short-Running Cron Worker (`app/Console/Kernel.php`) | **PASS** | `queue:work database --stop-when-empty --max-time=50 --timeout=45 --tries=3 --backoff=10` — verified live via `docker exec` (exited cleanly with code 0) |
| Prevent Double-Claiming (`retry_after > timeout`) | **PASS** | `retry_after = 90s` > `--timeout=45s` and `--max-time=50s`, preventing a slow job from being claimed twice while still running |
| Overlap Prevention (`withoutOverlapping(2)`) | **PASS** | Uses `CACHE_DRIVER=file` (supported on Hostinger); lock automatically expires after **2 minutes** if PHP crashes mid-run so locks can never remain stuck indefinitely |
| Failed Job Logging & Pruning | **PASS** | Retries 3 times (10s backoff), records failures in `failed_jobs` and `storage/logs/queue-cron.log`, prunes failed jobs older than 7 days (`queue:prune-failed --hours=168`) daily at `02:30` |
| Supplier Import Chunking & Duplicate Prevention | **PASS** | Fixed `app/Imports/SupplierImport.php`: increased `chunkSize()` from `1` to `100`, removed premature `return` inside `foreach`, added `DB::rollBack()` on exception, and enforced trimmed email duplicate check |
| POS & Stock Synchronicity | **PASS** | POS checkout (`SaleRepository::storeSale`), payment recording, stock deduction (`lockForUpdate()`), purchases, and transfers remain synchronous inside DB transactions |

## 2. Exact Hostinger Cron Command

First confirm the exact PHP 8.3 CLI binary path via SSH on Hostinger:
```bash
which php8.3 || which php
```

Then add **one** Cron Job in **Hostinger hPanel -> Advanced -> Cron Jobs** (Schedule: `* * * * *` — Every Minute):
```bash
/usr/bin/php8.3 /home/<u_account>/domains/<yourdomain.com>/pos-app/artisan schedule:run >> /dev/null 2>&1
```
*(If `which php8.3` is not present and `php -v` reports PHP 8.3.37 at `/usr/bin/php`, use `/usr/bin/php`.)*
