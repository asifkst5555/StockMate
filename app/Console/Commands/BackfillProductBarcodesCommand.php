<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\BarcodeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Safe, idempotent, tenant-scoped product barcode PNG backfill command (Phase B).
 *
 * Never modifies product prices, stock, SKUs, or supplier data.
 * Preserves existing valid barcode PNG files unless `--force` is explicitly passed.
 */
class BackfillProductBarcodesCommand extends Command
{
    protected $signature = 'stockmate:barcodes:backfill
        {--dry-run : Report missing barcodes without writing any files}
        {--tenant= : Restrict backfill to a specific tenant_id}
        {--product-id=* : Restrict backfill to specific product ID(s) for targeted retry}
        {--batch=100 : Number of products to process per chunk}
        {--force : Regenerate PNG even if a valid file already exists}';

    protected $description = 'Safely backfill missing product barcode PNG images in batches (idempotent).';

    public function handle(BarcodeService $barcodeService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $productIds = array_values(array_filter(
            array_map('intval', (array) $this->option('product-id')),
            static fn (int $id): bool => $id > 0
        ));
        $batchSize = max(1, min(1000, (int) $this->option('batch')));
        $force = (bool) $this->option('force');

        $query = Product::withoutGlobalScopes()->orderBy('id');
        if (! empty($tenantId)) {
            $query->where('tenant_id', (string) $tenantId);
        }
        if (! empty($productIds)) {
            $query->whereIn('id', $productIds);
        }

        $stats = [
            'scanned' => 0,
            'generated' => 0,
            'would_generate' => 0,
            'skipped_existing' => 0,
            'failed' => 0,
        ];
        $failedProductIds = [];

        $query->chunkById($batchSize, function ($products) use ($barcodeService, $force, $dryRun, &$stats, &$failedProductIds) {
            foreach ($products as $product) {
                $stats['scanned']++;

                try {
                    $result = $barcodeService->ensureProductBarcodePng($product, $force, $dryRun);
                } catch (\Throwable $e) {
                    $stats['failed']++;
                    $failedProductIds[] = $product->id;

                    Log::error('Barcode backfill encountered an unexpected per-product error.', [
                        'product_id' => $product->id,
                        'tenant_id' => $product->tenant_id,
                        'exception' => get_class($e),
                        'error' => $this->sanitizeErrorMessage($e->getMessage()),
                    ]);

                    continue;
                }

                if ($result['status'] === 'generated') {
                    $stats['generated']++;
                } elseif ($result['status'] === 'would_generate') {
                    $stats['would_generate']++;
                } elseif ($result['status'] === 'skipped_existing') {
                    $stats['skipped_existing']++;
                } else {
                    $stats['failed']++;
                    $failedProductIds[] = $product->id;

                    Log::warning('Barcode backfill skipped invalid product barcode.', [
                        'product_id' => $product->id,
                        'tenant_id' => $product->tenant_id,
                        'error' => $this->sanitizeErrorMessage((string) ($result['error'] ?? 'Invalid product barcode.')),
                    ]);
                }
            }
        });

        $this->info(sprintf(
            'Barcode backfill %s: scanned=%d, generated=%d, would_generate=%d, skipped_existing=%d, failed=%d',
            $dryRun ? '(DRY RUN)' : 'complete',
            $stats['scanned'],
            $stats['generated'],
            $stats['would_generate'],
            $stats['skipped_existing'],
            $stats['failed']
        ));

        if (! empty($failedProductIds)) {
            $sampleIds = array_slice($failedProductIds, 0, 25);
            $suffix = count($failedProductIds) > 25 ? ' (truncated; see logs for full list)' : '';
            $this->warn('Failed product IDs: ' . implode(', ', $sampleIds) . $suffix);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Strip control characters, file paths, and excess length before logging error details.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        $clean = preg_replace('/[\r\n\t]+/', ' ', trim($message)) ?? '';
        $clean = preg_replace('/(?:\/[^\s:]+)+/', '[path]', $clean) ?? $clean;

        return mb_substr($clean !== '' ? $clean : 'Unexpected barcode processing error.', 0, 200);
    }
}
