<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * Hostinger Business Hosting compatible:
     * - Short-running database queue worker (--stop-when-empty --max-time=50)
     * - withoutOverlapping(2) prevents duplicate concurrent runs
     * - Retries up to 3 times with 10s backoff; failures logged to failed_jobs
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('queue:work database --stop-when-empty --max-time=50 --timeout=45 --tries=3 --backoff=10')
            ->everyMinute()
            ->withoutOverlapping(2)
            ->appendOutputTo(storage_path('logs/queue-cron.log'));

        $schedule->command('queue:prune-failed --hours=168')
            ->dailyAt('02:30')
            ->withoutOverlapping(10);

        $schedule->command('sanctum:prune-expired --hours=24')
            ->dailyAt('03:00')
            ->withoutOverlapping(10);
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
