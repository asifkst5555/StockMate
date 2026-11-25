<?php

namespace App\Console\Commands;

use App\Models\PaymentLifecycleEvent;
use Illuminate\Console\Command;

class PaymentReviewQueueCommand extends Command
{
    protected $signature = 'stockmate:payments:review-queue {--limit=50 : Maximum records to display}';

    protected $description = 'Read-only list of payment refund/dispute lifecycle events requiring Super Admin review.';

    public function handle(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));

        $rows = PaymentLifecycleEvent::where('requires_admin_review', true)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentLifecycleEvent $l) => [
                $l->id,
                $l->provider,
                $l->provider_lifecycle_id,
                $l->transaction_id ?? '-',
                $l->lifecycle_type,
                $l->status,
                $l->amount_minor . ' ' . $l->currency,
                $l->occurred_at?->toDateTimeString() ?? '-',
            ])
            ->all();

        if (empty($rows)) {
            $this->info('No payment lifecycle events pending Super Admin review.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Provider', 'Lifecycle ID', 'Tx ID', 'Type', 'Status', 'Amount', 'Occurred At'],
            $rows
        );

        return self::SUCCESS;
    }
}
