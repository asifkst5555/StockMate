<?php

namespace App\Console\Commands;

use App\Models\PaymentReconciliation;
use Illuminate\Console\Command;

class PaymentReconciliationCommand extends Command
{
    protected $signature = 'stockmate:payments:reconciliation {--limit=50 : Maximum records to display}';

    protected $description = 'Read-only list of unresolved payment refund reconciliation records.';

    public function handle(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));

        $rows = PaymentReconciliation::where('status', PaymentReconciliation::STATUS_PENDING)
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentReconciliation $r) => [
                $r->id,
                $r->provider,
                $r->provider_charge_id,
                $r->provider_payment_intent_id ?? '-',
                $r->transaction_id ?? '-',
                $r->reason_code,
                $r->status,
                $r->detected_at?->toDateTimeString() ?? '-',
            ])
            ->all();

        if (empty($rows)) {
            $this->info('No pending payment reconciliation records found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Provider', 'Charge ID', 'PaymentIntent ID', 'Tx ID', 'Reason', 'Status', 'Detected At'],
            $rows
        );

        return self::SUCCESS;
    }
}
