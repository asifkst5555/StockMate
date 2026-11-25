<?php

namespace App\Services;

use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Atomic, tenant- and user-scoped Idempotency-Key guard for POS/Sales checkout (Phase C1.7).
 *
 * Guarantees:
 * - Requires a Cache store implementing LockProvider (never silently degrades to non-atomic).
 * - Prevents duplicate sales, duplicate payments, and double stock deduction under concurrent
 *   double-submits, network retries, or post-commit failures before HTTP response completion.
 * - Releases the key cleanly on pre-commit validation/server errors so corrected retries succeed.
 * - Verifies tenant & user ownership on every replay.
 */
class SaleIdempotencyService
{
    private const LOCK_TTL_SECONDS = 120;
    private const RECORD_TTL_SECONDS = 86400; // 24 hours

    /**
     * Validate and normalize an optional Idempotency-Key header.
     */
    public function normalizeKey(?string $rawKey): ?string
    {
        if ($rawKey === null) {
            return null;
        }

        $key = trim($rawKey);
        if ($key === '') {
            return null;
        }

        if (strlen($key) > 128 || ! preg_match('/^[A-Za-z0-9_\-\.:]+$/', $key)) {
            throw new UnprocessableEntityHttpException('Invalid Idempotency-Key header format.');
        }

        return $key;
    }

    /**
     * Compute a deterministic SHA-256 hash of the checkout payload to detect key reuse with a different cart.
     */
    public function computePayloadHash(array $input): string
    {
        $canonical = [
            'customer_id' => $input['customer_id'] ?? null,
            'warehouse_id' => $input['warehouse_id'] ?? null,
            'discount' => (float) ($input['discount'] ?? 0),
            'tax_rate' => (float) ($input['tax_rate'] ?? 0),
            'shipping' => (float) ($input['shipping'] ?? 0),
            'payment_status' => $input['payment_status'] ?? null,
            'status' => $input['status'] ?? null,
            'sale_items' => array_map(static function ($item) {
                return [
                    'product_id' => $item['product_id'] ?? null,
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'product_price' => isset($item['product_price']) ? (float) $item['product_price'] : null,
                    'discount_type' => $item['discount_type'] ?? null,
                    'discount_value' => (float) ($item['discount_value'] ?? 0),
                    'tax_type' => $item['tax_type'] ?? null,
                    'tax_value' => (float) ($item['tax_value'] ?? 0),
                ];
            }, is_array($input['sale_items'] ?? null) ? $input['sale_items'] : []),
            'payment_details' => array_map(static function ($detail) {
                return [
                    'amount' => (float) ($detail['amount'] ?? 0),
                    'payment_type' => $detail['payment_type']['value'] ?? ($detail['payment_type'] ?? null),
                ];
            }, is_array($input['payment_details'] ?? null) ? $input['payment_details'] : []),
        ];

        return hash('sha256', json_encode($canonical));
    }

    /**
     * Execute a sale creation callback under an atomic tenant+user-scoped idempotency lock.
     *
     * @param  callable(): Sale  $createSaleCallback  Must return the committed Sale model.
     * @param  callable(Sale): void|null  $afterCommitCallback  Optional post-commit side effects (e.g., hold cleanup).
     * @return array{sale: Sale, replayed: bool}
     */
    public function execute(
        User $user,
        string $idempotencyKey,
        array $input,
        callable $createSaleCallback,
        ?callable $afterCommitCallback = null
    ): array {
        $store = Cache::getStore();
        if (! $store instanceof LockProvider) {
            throw new ServiceUnavailableHttpException(
                null,
                'Atomic idempotency locking is unavailable for the configured cache driver.'
            );
        }

        $tenantId = (string) ($user->tenant_id ?? 'global');
        $userId = (int) $user->id;
        $scopeHash = hash('sha256', $idempotencyKey);
        $recordKey = "pos_sale_idempotency:{$tenantId}:{$userId}:{$scopeHash}";
        $lockKey = "pos_sale_idempotency_lock:{$tenantId}:{$userId}:{$scopeHash}";
        $payloadHash = $this->computePayloadHash($input);

        $lock = Cache::lock($lockKey, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new ConflictHttpException('Checkout request is already being processed. Please wait.');
        }

        $ownerToken = Str::random(32);
        $saleCommitted = false;

        try {
            $existing = Cache::get($recordKey);
            if (is_array($existing)) {
                if (($existing['status'] ?? null) === 'processing') {
                    throw new ConflictHttpException('Checkout request is already being processed. Please wait.');
                }

                if (($existing['status'] ?? null) === 'completed') {
                    if (($existing['payload_hash'] ?? '') !== $payloadHash) {
                        throw new ConflictHttpException('Idempotency key has already been used with a different checkout payload.');
                    }

                    $replayedSale = $this->resolveAuthorizedReplaySale($user, $existing);

                    return ['sale' => $replayedSale, 'replayed' => true];
                }
            }

            // Record in-flight processing marker (prevents duplicate processing even if lock TTL is approached)
            Cache::put($recordKey, [
                'status' => 'processing',
                'owner_token' => $ownerToken,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'payload_hash' => $payloadHash,
                'started_at' => now()->timestamp,
            ], self::LOCK_TTL_SECONDS * 2);

            /** @var Sale $sale */
            $sale = $createSaleCallback();
            $saleCommitted = true;

            // Persist completed idempotency record IMMEDIATELY after DB commit, BEFORE any post-commit side effects
            Cache::put($recordKey, [
                'status' => 'completed',
                'sale_id' => (int) $sale->id,
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'payload_hash' => $payloadHash,
                'completed_at' => now()->timestamp,
            ], self::RECORD_TTL_SECONDS);

            if ($afterCommitCallback !== null) {
                $afterCommitCallback($sale);
            }

            return ['sale' => $sale, 'replayed' => false];
        } catch (\Throwable $e) {
            if (! $saleCommitted) {
                $current = Cache::get($recordKey);
                if (is_array($current) && ($current['owner_token'] ?? null) === $ownerToken) {
                    Cache::forget($recordKey);
                }
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Verify cached replay ownership against both active tenant and authenticated user.
     */
    private function resolveAuthorizedReplaySale(User $user, array $record): Sale
    {
        $isSuperAdmin = $user->hasRole(Role::SUPER_ADMIN);
        $activeTenantId = (string) ($user->tenant_id ?? 'global');
        $saleId = (int) ($record['sale_id'] ?? 0);

        if (! $isSuperAdmin) {
            if (
                (string) ($record['tenant_id'] ?? '') !== $activeTenantId
                || (int) ($record['user_id'] ?? 0) !== (int) $user->id
            ) {
                throw new ConflictHttpException('Idempotency key replay denied for unauthorized tenant or user.');
            }
        }

        $query = Sale::whereKey($saleId);
        if (! $isSuperAdmin) {
            $query->where('tenant_id', $activeTenantId);
        }

        $sale = $query->first();
        if (! $sale) {
            throw new ConflictHttpException('Previously committed sale for this Idempotency-Key is no longer available.');
        }

        return $sale;
    }
}
