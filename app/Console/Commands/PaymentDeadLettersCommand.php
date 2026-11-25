<?php

namespace App\Console\Commands;

use App\Models\PaymentWebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class PaymentDeadLettersCommand extends Command
{
    protected $signature = 'stockmate:payments:dead-letters {--limit=50 : Maximum records to display}';

    protected $description = 'Read-only list of dead-lettered and failed payment webhook events (safe fields only).';

    public function handle(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));

        $rows = PaymentWebhookEvent::whereIn('status', [
            PaymentWebhookEvent::STATUS_DEAD_LETTER,
            PaymentWebhookEvent::STATUS_FAILED,
        ])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentWebhookEvent $e) => [
                $e->id,
                $e->provider,
                $e->event_id,
                $e->event_type,
                $e->status,
                $e->attempts,
                Str::limit((string) $e->last_error, 80),
                $e->updated_at?->toDateTimeString() ?? '-',
            ])
            ->all();

        if (empty($rows)) {
            $this->info('No dead-lettered or failed payment webhook events found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Provider', 'Event ID', 'Type', 'Status', 'Attempts', 'Last Error', 'Updated At'],
            $rows
        );

        return self::SUCCESS;
    }
}
