<?php

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\SubscriptionRepository;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Authoritative server-side PaymentIntent lifecycle manager.
 * Binds checkout creation to callback/webhook verification with row locking,
 * DB uniqueness, and post-rollback fresh-transaction collision recovery.
 */
class PaymentIntentService
{
    public const DEFAULT_TTL_MINUTES = 60;

    private SubscriptionRepository $subscriptionRepository;

    public function __construct(SubscriptionRepository $subscriptionRepository)
    {
        $this->subscriptionRepository = $subscriptionRepository;
    }

    public function createIntent(
        User $user,
        Plan $plan,
        int $gateway,
        ?array $stores = null,
        string $providerKey = 'generic',
        int $ttlMinutes = self::DEFAULT_TTL_MINUTES
    ): PaymentIntent {
        $pricing = SubscriptionRepository::calculatePayableAmount($plan, $user->id);

        if ($pricing['payable_amount'] <= 0) {
            throw new UnprocessableEntityHttpException('Payable amount must be greater than zero for gateway checkout.');
        }

        $currency = strtoupper($pricing['currency_code']);
        $amountMinor = CurrencyMinorUnit::toMinorUnits($pricing['payable_amount'], $currency, $providerKey);

        if ($amountMinor <= 0) {
            throw new UnprocessableEntityHttpException('Calculated payable minor units must be greater than zero.');
        }

        $validatedStores = $this->filterOwnedStores($user->id, $stores);

        return PaymentIntent::create([
            'reference' => 'pi_' . bin2hex(random_bytes(12)),
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'plan_id' => $plan->id,
            'gateway' => $gateway,
            'amount_minor' => $amountMinor,
            'amount_decimal' => CurrencyMinorUnit::fromMinorUnits($amountMinor, $currency, $providerKey),
            'currency' => $currency,
            'stores' => $validatedStores,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
        ]);
    }

    public function attachProviderReference(PaymentIntent $intent, string $providerReference): PaymentIntent
    {
        $intent->update(['provider_reference' => $providerReference]);

        return $intent->refresh();
    }

    public function findForGateway(int $gateway, ?string $providerReference = null, ?string $internalReference = null): ?PaymentIntent
    {
        if (! empty($providerReference)) {
            $byProvider = PaymentIntent::where('gateway', $gateway)
                ->where('provider_reference', $providerReference)
                ->first();
            if ($byProvider) {
                return $byProvider;
            }
        }

        if (! empty($internalReference)) {
            $byRef = PaymentIntent::where('gateway', $gateway)
                ->where('reference', $internalReference)
                ->first();
            if ($byRef) {
                if (! empty($providerReference) && ! empty($byRef->provider_reference) && $byRef->provider_reference !== $providerReference) {
                    return null;
                }

                return $byRef;
            }
        }

        return null;
    }

    /**
     * Extracted for clean testability of concurrent unique-constraint collisions.
     */
    protected function insertPaidTransaction(
        PaymentIntent $lockedIntent,
        string $providerTxId,
        int $actualAmountMinor,
        array $meta,
        string $providerKey,
        ?string $providerPaymentIntentId = null
    ): Transaction {
        return Transaction::create([
            'tenant_id' => $lockedIntent->tenant_id,
            'transaction_id' => $providerTxId,
            'provider_payment_intent_id' => $providerPaymentIntentId ?: null,
            'amount' => CurrencyMinorUnit::fromMinorUnits($actualAmountMinor, $lockedIntent->currency, $providerKey),
            'type' => $lockedIntent->gateway,
            'status' => Transaction::PAID,
            'user_id' => $lockedIntent->user_id,
            'meta' => json_encode($meta),
        ]);
    }

    /**
     * Atomically verify and settle a PaymentIntent into a PAID Transaction + ACTIVE Subscription.
     *
     * F-04 fix: Unique-constraint collision recovery runs strictly AFTER the failed
     * transaction rolls back, inside a fresh transaction with locking current reads
     * (never inside a stale InnoDB REPEATABLE READ snapshot).
     *
     * @return array{idempotent: bool, intent: PaymentIntent, transaction: Transaction, subscription: Subscription}
     */
    public function settleIntent(
        PaymentIntent $intent,
        string $providerTxId,
        int $actualAmountMinor,
        string $actualCurrency,
        array $meta = [],
        ?int $providerUserId = null,
        ?int $providerPlanId = null,
        string $providerKey = 'generic',
        ?string $providerPaymentIntentId = null
    ): array {
        if (trim($providerTxId) === '') {
            throw new UnprocessableEntityHttpException('Provider transaction ID is required.');
        }

        $normalizedPiId = is_string($providerPaymentIntentId) && trim($providerPaymentIntentId) !== ''
            ? trim($providerPaymentIntentId)
            : null;
        $expiredDetected = false;

        try {
            $result = DB::transaction(function () use (
                $intent,
                $providerTxId,
                $actualAmountMinor,
                $actualCurrency,
                $meta,
                $providerUserId,
                $providerPlanId,
                $providerKey,
                $normalizedPiId,
                &$expiredDetected
            ) {
                $lockedIntent = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();

                if ($providerUserId !== null && (int) $providerUserId !== (int) $lockedIntent->user_id) {
                    throw new UnprocessableEntityHttpException('Payment intent user mismatch.');
                }
                if ($providerPlanId !== null && (int) $providerPlanId !== (int) $lockedIntent->plan_id) {
                    throw new UnprocessableEntityHttpException('Payment intent plan mismatch.');
                }

                if ($actualAmountMinor <= 0 || $actualAmountMinor !== (int) $lockedIntent->amount_minor) {
                    throw new UnprocessableEntityHttpException('Payment amount does not match expected intent amount.');
                }
                if (strtoupper(trim($actualCurrency)) !== strtoupper($lockedIntent->currency)) {
                    throw new UnprocessableEntityHttpException('Payment currency does not match expected intent currency.');
                }

                if ($lockedIntent->status === PaymentIntent::STATUS_PAID) {
                    $existingTx = $lockedIntent->transaction_id
                        ? Transaction::find($lockedIntent->transaction_id)
                        : null;
                    $existingSub = $lockedIntent->subscription_id
                        ? Subscription::find($lockedIntent->subscription_id)
                        : null;

                    if ($existingTx && $existingTx->transaction_id === $providerTxId && $existingSub) {
                        return [
                            'idempotent' => true,
                            'intent' => $lockedIntent,
                            'transaction' => $existingTx,
                            'subscription' => $existingSub,
                        ];
                    }

                    throw new UnprocessableEntityHttpException('Payment intent has already been settled with a different transaction.');
                }

                if ($lockedIntent->status !== PaymentIntent::STATUS_PENDING) {
                    throw new UnprocessableEntityHttpException("Payment intent is not pending (status: {$lockedIntent->status}).");
                }

                if ($lockedIntent->isExpired()) {
                    $expiredDetected = true;

                    return null;
                }

                $existingTx = Transaction::where('transaction_id', $providerTxId)
                    ->where('type', $lockedIntent->gateway)
                    ->lockForUpdate()
                    ->first();

                if ($existingTx) {
                    if ((int) $existingTx->user_id !== (int) $lockedIntent->user_id) {
                        throw new UnprocessableEntityHttpException('Transaction belongs to another user.');
                    }

                    $existingSub = Subscription::where('transaction_id', $existingTx->id)->lockForUpdate()->first();
                    if ($existingSub) {
                        if ($lockedIntent->transaction_id && (int) $lockedIntent->transaction_id !== (int) $existingTx->id) {
                            throw new UnprocessableEntityHttpException('Transaction has already been used for another subscription.');
                        }
                        $otherIntent = PaymentIntent::where('transaction_id', $existingTx->id)
                            ->where('id', '!=', $lockedIntent->id)
                            ->exists();
                        if ($otherIntent) {
                            throw new UnprocessableEntityHttpException('Transaction has already been used for another payment intent.');
                        }

                        $lockedIntent->update([
                            'status' => PaymentIntent::STATUS_PAID,
                            'transaction_id' => $existingTx->id,
                            'subscription_id' => $existingSub->id,
                            'paid_at' => $lockedIntent->paid_at ?? Carbon::now(),
                        ]);

                        return [
                            'idempotent' => true,
                            'intent' => $lockedIntent->refresh(),
                            'transaction' => $existingTx,
                            'subscription' => $existingSub,
                        ];
                    }

                    $transaction = $existingTx;
                } else {
                    $transaction = $this->insertPaidTransaction(
                        $lockedIntent,
                        $providerTxId,
                        $actualAmountMinor,
                        $meta,
                        $providerKey,
                        $normalizedPiId
                    );
                }

                // Backfill provider_payment_intent_id if newly available on an existing tx
                if ($normalizedPiId && empty($transaction->provider_payment_intent_id)) {
                    $transaction->update(['provider_payment_intent_id' => $normalizedPiId]);
                }

                $subscription = $this->subscriptionRepository->createSubscription([
                    'plan_id' => $lockedIntent->plan_id,
                    'user_id' => $lockedIntent->user_id,
                    'stores' => $lockedIntent->stores,
                    'payment_type' => $lockedIntent->gateway,
                    'transaction_id' => $transaction->id,
                    'verified_gateway_payment' => true,
                ]);

                $intentUpdate = [
                    'status' => PaymentIntent::STATUS_PAID,
                    'transaction_id' => $transaction->id,
                    'subscription_id' => $subscription->id,
                    'paid_at' => Carbon::now(),
                ];
                if ($normalizedPiId && empty($lockedIntent->provider_payment_intent_id)) {
                    $intentUpdate['provider_payment_intent_id'] = $normalizedPiId;
                }
                $lockedIntent->update($intentUpdate);

                return [
                    'idempotent' => false,
                    'intent' => $lockedIntent->refresh(),
                    'transaction' => $transaction,
                    'subscription' => $subscription,
                ];
            });
        } catch (QueryException $e) {
            // F-04: Only recover from payment idempotency duplicate-key collisions AFTER rollback
            if (! $this->isPaymentIdempotencyUniqueCollision($e)) {
                throw $e;
            }

            return $this->recoverAfterUniqueCollision(
                $intent,
                $providerTxId,
                $actualAmountMinor,
                $actualCurrency,
                $providerKey
            );
        }

        if ($expiredDetected) {
            $this->markTerminalFailure($intent, PaymentIntent::STATUS_EXPIRED);
            throw new UnprocessableEntityHttpException('Payment intent has expired.');
        }

        return $result;
    }

    /**
     * Fresh-transaction locking recovery after a concurrent duplicate-key collision.
     *
     * @return array{idempotent: bool, intent: PaymentIntent, transaction: Transaction, subscription: Subscription}
     */
    private function recoverAfterUniqueCollision(
        PaymentIntent $intent,
        string $providerTxId,
        int $actualAmountMinor,
        string $actualCurrency,
        string $providerKey
    ): array {
        return DB::transaction(function () use ($intent, $providerTxId, $actualAmountMinor, $actualCurrency, $providerKey) {
            $lockedIntent = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();

            if ($actualAmountMinor !== (int) $lockedIntent->amount_minor || strtoupper(trim($actualCurrency)) !== strtoupper($lockedIntent->currency)) {
                throw new UnprocessableEntityHttpException('Concurrent recovery rejected due to amount/currency mismatch.');
            }

            $racedTx = Transaction::where('transaction_id', $providerTxId)
                ->where('type', $lockedIntent->gateway)
                ->lockForUpdate()
                ->first();

            if (! $racedTx || (int) $racedTx->user_id !== (int) $lockedIntent->user_id) {
                throw new UnprocessableEntityHttpException('Transaction belongs to another user or could not be verified.');
            }

            // Ensure this intent wasn't already settled by a different transaction
            if ($lockedIntent->status === PaymentIntent::STATUS_PAID && (int) $lockedIntent->transaction_id !== (int) $racedTx->id) {
                throw new UnprocessableEntityHttpException('Payment intent was already settled with a different transaction.');
            }

            // Ensure the raced transaction is not bound to a different intent
            $boundToOtherIntent = PaymentIntent::where('transaction_id', $racedTx->id)
                ->where('id', '!=', $lockedIntent->id)
                ->exists();
            if ($boundToOtherIntent) {
                throw new UnprocessableEntityHttpException('Transaction is already bound to another payment intent.');
            }

            $racedSub = Subscription::where('transaction_id', $racedTx->id)->lockForUpdate()->first();
            if (! $racedSub || (int) $racedSub->user_id !== (int) $lockedIntent->user_id || (int) $racedSub->plan_id !== (int) $lockedIntent->plan_id) {
                throw new UnprocessableEntityHttpException('Concurrent payment transaction has no matching subscription for this intent.');
            }

            if ($lockedIntent->status !== PaymentIntent::STATUS_PAID) {
                $lockedIntent->update([
                    'status' => PaymentIntent::STATUS_PAID,
                    'transaction_id' => $racedTx->id,
                    'subscription_id' => $racedSub->id,
                    'paid_at' => $lockedIntent->paid_at ?? Carbon::now(),
                ]);
            }

            return [
                'idempotent' => true,
                'intent' => $lockedIntent->refresh(),
                'transaction' => $racedTx,
                'subscription' => $racedSub,
            ];
        });
    }

    public function markTerminalFailure(PaymentIntent $intent, string $status = PaymentIntent::STATUS_FAILED): void
    {
        if (! in_array($status, [
            PaymentIntent::STATUS_FAILED,
            PaymentIntent::STATUS_CANCELLED,
            PaymentIntent::STATUS_EXPIRED,
        ], true)) {
            return;
        }

        DB::transaction(function () use ($intent, $status) {
            $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->first();
            if ($locked && $locked->status === PaymentIntent::STATUS_PENDING) {
                $locked->update(['status' => $status]);
            }
        });
    }

    private function filterOwnedStores(int $userId, ?array $stores): ?array
    {
        if (empty($stores)) {
            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $stores))));
        if (empty($ids)) {
            return null;
        }

        $owned = Store::where('user_id', $userId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return empty($owned) ? null : $owned;
    }

    /**
     * Strictly match MySQL duplicate-entry (1062) on payment idempotency unique indexes.
     * Unrelated SQLSTATE 23000 errors (e.g., FK 1452 or NOT NULL 1048) return false and are rethrown.
     */
    private function isPaymentIdempotencyUniqueCollision(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);
        $message = $e->getMessage();

        $isDuplicateKey = ($driverCode === 1062)
            || ($sqlState === '23000' && str_contains($message, 'Duplicate entry'));

        if (! $isDuplicateKey) {
            return false;
        }

        return str_contains($message, 'transactions_transaction_id_type_unique')
            || str_contains($message, 'subscriptions_transaction_id_unique');
    }
}
