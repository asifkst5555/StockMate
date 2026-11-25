<?php

namespace App\Services;

use App\Models\PaymentIntent;
use App\Models\PaymentLifecycleEvent;
use App\Models\PaymentReconciliation;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Refund & Dispute Lifecycle Ledger Service (F-05).
 *
 * Business policy compliance:
 * - Preserves original Transaction and Subscription records untouched.
 * - Flags every refund/dispute event for Super Admin review (`requires_admin_review = true`).
 * - Does NOT automatically revoke subscriptions without explicit business policy authorization.
 */
class PaymentLifecycleService
{
    /**
     * Resolve an original PAID Transaction for Stripe lifecycle events via trusted references:
     * 1. Stripe PaymentIntent ID (`pi_*`) persisted on `transactions` / `payment_intents`.
     * 2. Verified server-created `intent_ref` metadata propagated via `payment_intent_data.metadata`.
     * 3. Legacy/historical Checkout Session ID (`cs_*`) on `transactions.transaction_id`.
     *
     * Rejects ambiguous or mismatched references (e.g., `pi_*` and `intent_ref` pointing to different records).
     */
    public function resolveStripeTransaction(
        ?string $stripePaymentIntentId = null,
        ?string $intentRef = null,
        ?string $fallbackSessionId = null
    ): Transaction {
        $piId = is_string($stripePaymentIntentId) && trim($stripePaymentIntentId) !== '' ? trim($stripePaymentIntentId) : null;
        $ref = is_string($intentRef) && trim($intentRef) !== '' ? trim($intentRef) : null;
        $csId = is_string($fallbackSessionId) && trim($fallbackSessionId) !== '' ? trim($fallbackSessionId) : null;

        $txByPi = null;
        if ($piId !== null) {
            $txByPi = Transaction::where('type', Subscription::TYPE_STRIPE)
                ->where('provider_payment_intent_id', $piId)
                ->lockForUpdate()
                ->first();

            if (! $txByPi) {
                $intentByPi = PaymentIntent::where('gateway', Subscription::TYPE_STRIPE)
                    ->where('provider_payment_intent_id', $piId)
                    ->first();
                if ($intentByPi && $intentByPi->transaction_id) {
                    $txByPi = Transaction::whereKey($intentByPi->transaction_id)
                        ->where('type', Subscription::TYPE_STRIPE)
                        ->lockForUpdate()
                        ->first();
                }
            }
        }

        $txByRef = null;
        if ($ref !== null) {
            $intentByRef = PaymentIntent::where('gateway', Subscription::TYPE_STRIPE)
                ->where('reference', $ref)
                ->first();
            if ($intentByRef && $intentByRef->transaction_id) {
                $txByRef = Transaction::whereKey($intentByRef->transaction_id)
                    ->where('type', Subscription::TYPE_STRIPE)
                    ->lockForUpdate()
                    ->first();
            }
        }

        // Ambiguity / mismatch guard: if both PI ID and intent_ref resolve, they MUST point to the same Transaction
        if ($txByPi && $txByRef && (int) $txByPi->id !== (int) $txByRef->id) {
            throw new UnprocessableEntityHttpException('Ambiguous or mismatched Stripe correlation references.');
        }

        // If PI ID was provided and resolved to a different PI than the intent_ref's stored PI, reject
        if ($piId !== null && $txByRef && ! empty($txByRef->provider_payment_intent_id) && $txByRef->provider_payment_intent_id !== $piId) {
            throw new UnprocessableEntityHttpException('Stripe PaymentIntent ID does not match correlated transaction.');
        }

        $resolved = $txByPi ?? $txByRef;

        if (! $resolved && $csId !== null) {
            $resolved = Transaction::where('type', Subscription::TYPE_STRIPE)
                ->where('transaction_id', $csId)
                ->lockForUpdate()
                ->first();
        }

        if (! $resolved) {
            throw new UnprocessableEntityHttpException('Original Stripe transaction could not be correlated safely.');
        }

        return $resolved;
    }

    /**
     * Record a refund or reversed-payment lifecycle event against an original PAID transaction.
     *
     * @return array{idempotent: bool, event: PaymentLifecycleEvent, total_refunded_minor: int}
     */
    public function recordRefund(
        string $provider,
        int $gatewayType,
        string $providerEventId,
        string $providerLifecycleId,
        string $providerTransactionId,
        int $refundAmountMinor,
        string $currency,
        bool $isReversed = false,
        array $meta = [],
        string $providerKey = 'generic',
        ?string $stripePaymentIntentId = null,
        ?string $intentRef = null
    ): array {
        if ($refundAmountMinor <= 0) {
            throw new UnprocessableEntityHttpException('Refund amount must be greater than zero.');
        }

        return DB::transaction(function () use (
            $provider,
            $gatewayType,
            $providerEventId,
            $providerLifecycleId,
            $providerTransactionId,
            $refundAmountMinor,
            $currency,
            $isReversed,
            $meta,
            $providerKey,
            $stripePaymentIntentId,
            $intentRef
        ) {
            // 1. Deduplicate by (provider, provider_event_id)
            $existingByEvent = PaymentLifecycleEvent::where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->lockForUpdate()
                ->first();
            if ($existingByEvent) {
                $total = $this->sumRefundedMinor($existingByEvent->transaction_id);

                return ['idempotent' => true, 'event' => $existingByEvent, 'total_refunded_minor' => $total];
            }

            // 2. Deduplicate by (provider, provider_lifecycle_id) for refunds (prevents double accounting)
            $existingByLifecycle = PaymentLifecycleEvent::where('provider', $provider)
                ->where('provider_lifecycle_id', $providerLifecycleId)
                ->whereIn('lifecycle_type', [
                    PaymentLifecycleEvent::TYPE_FULL_REFUND,
                    PaymentLifecycleEvent::TYPE_PARTIAL_REFUND,
                    PaymentLifecycleEvent::TYPE_REVERSED_PAYMENT,
                ])
                ->lockForUpdate()
                ->first();
            if ($existingByLifecycle) {
                $total = $this->sumRefundedMinor($existingByLifecycle->transaction_id);

                return ['idempotent' => true, 'event' => $existingByLifecycle, 'total_refunded_minor' => $total];
            }

            // 3. Bind strictly to original provider Transaction & PaymentIntent
            if ($gatewayType === Subscription::TYPE_STRIPE) {
                $tx = $this->resolveStripeTransaction($stripePaymentIntentId, $intentRef, $providerTransactionId);
            } else {
                $tx = Transaction::where('transaction_id', $providerTransactionId)
                    ->where('type', $gatewayType)
                    ->lockForUpdate()
                    ->first();

                if (! $tx) {
                    throw new UnprocessableEntityHttpException('Original transaction not found for refund event.');
                }
            }

            $intent = PaymentIntent::where('transaction_id', $tx->id)->first();
            $subscription = Subscription::where('transaction_id', $tx->id)->first();

            $expectedCurrency = strtoupper($intent?->currency ?? $currency);
            if (strtoupper(trim($currency)) !== $expectedCurrency) {
                throw new UnprocessableEntityHttpException('Refund currency does not match original payment currency.');
            }

            $originalCapturedMinor = $intent
                ? (int) $intent->amount_minor
                : CurrencyMinorUnit::toMinorUnits($tx->amount, $expectedCurrency, $providerKey);

            $alreadyRefundedMinor = $this->sumRefundedMinor($tx->id);
            $newTotalMinor = $alreadyRefundedMinor + $refundAmountMinor;

            if ($newTotalMinor > $originalCapturedMinor) {
                throw new UnprocessableEntityHttpException('Refund amount exceeds original captured payment amount.');
            }

            if ($isReversed) {
                $lifecycleType = PaymentLifecycleEvent::TYPE_REVERSED_PAYMENT;
                $status = PaymentLifecycleEvent::STATUS_REVERSED;
            } elseif ($newTotalMinor === $originalCapturedMinor) {
                $lifecycleType = PaymentLifecycleEvent::TYPE_FULL_REFUND;
                $status = PaymentLifecycleEvent::STATUS_RECORDED;
            } else {
                $lifecycleType = PaymentLifecycleEvent::TYPE_PARTIAL_REFUND;
                $status = PaymentLifecycleEvent::STATUS_RECORDED;
            }

            $event = $this->insertLifecycleEvent([
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'provider_lifecycle_id' => $providerLifecycleId,
                'provider_transaction_id' => $providerTransactionId,
                'transaction_id' => $tx->id,
                'payment_intent_id' => $intent?->id,
                'subscription_id' => $subscription?->id,
                'user_id' => $tx->user_id,
                'lifecycle_type' => $lifecycleType,
                'status' => $status,
                'amount_minor' => $refundAmountMinor,
                'currency' => $expectedCurrency,
                'requires_admin_review' => true,
                'meta' => $meta,
                'occurred_at' => Carbon::now(),
            ]);

            return [
                'idempotent' => false,
                'event' => $event,
                'total_refunded_minor' => $newTotalMinor,
            ];
        });
    }

    /**
     * Record a dispute or chargeback lifecycle event with out-of-order protection.
     * Never automatically revokes subscriptions; flags for Super Admin review.
     *
     * @return array{idempotent: bool, event: PaymentLifecycleEvent}
     */
    public function recordDispute(
        string $provider,
        int $gatewayType,
        string $providerEventId,
        string $providerDisputeId,
        string $providerTransactionId,
        string $lifecycleType,
        int $disputeAmountMinor,
        string $currency,
        array $meta = [],
        ?string $stripePaymentIntentId = null,
        ?string $intentRef = null
    ): array {
        $allowedTypes = [
            PaymentLifecycleEvent::TYPE_CHARGEBACK,
            PaymentLifecycleEvent::TYPE_DISPUTE_OPENED,
            PaymentLifecycleEvent::TYPE_DISPUTE_WON,
            PaymentLifecycleEvent::TYPE_DISPUTE_LOST,
        ];

        if (! in_array($lifecycleType, $allowedTypes, true)) {
            throw new UnprocessableEntityHttpException('Unsupported dispute lifecycle type.');
        }

        if ($disputeAmountMinor < 0) {
            throw new UnprocessableEntityHttpException('Dispute amount cannot be negative.');
        }

        return DB::transaction(function () use (
            $provider,
            $gatewayType,
            $providerEventId,
            $providerDisputeId,
            $providerTransactionId,
            $lifecycleType,
            $disputeAmountMinor,
            $currency,
            $meta,
            $stripePaymentIntentId,
            $intentRef
        ) {
            $existingByEvent = PaymentLifecycleEvent::where('provider', $provider)
                ->where('provider_event_id', $providerEventId)
                ->lockForUpdate()
                ->first();
            if ($existingByEvent) {
                return ['idempotent' => true, 'event' => $existingByEvent];
            }

            $existingSameType = PaymentLifecycleEvent::where('provider', $provider)
                ->where('provider_lifecycle_id', $providerDisputeId)
                ->where('lifecycle_type', $lifecycleType)
                ->lockForUpdate()
                ->first();
            if ($existingSameType) {
                return ['idempotent' => true, 'event' => $existingSameType];
            }

            // Out-of-order guard: if dispute is already resolved (won/lost), a late dispute_opened/chargeback
            // does NOT reopen or overwrite the terminal resolution.
            $existingResolved = PaymentLifecycleEvent::where('provider', $provider)
                ->where('provider_lifecycle_id', $providerDisputeId)
                ->whereIn('lifecycle_type', [
                    PaymentLifecycleEvent::TYPE_DISPUTE_WON,
                    PaymentLifecycleEvent::TYPE_DISPUTE_LOST,
                ])
                ->lockForUpdate()
                ->first();

            if ($existingResolved && in_array($lifecycleType, [
                PaymentLifecycleEvent::TYPE_DISPUTE_OPENED,
                PaymentLifecycleEvent::TYPE_CHARGEBACK,
            ], true)) {
                return ['idempotent' => true, 'event' => $existingResolved];
            }

            if ($gatewayType === Subscription::TYPE_STRIPE) {
                $tx = $this->resolveStripeTransaction($stripePaymentIntentId, $intentRef, $providerTransactionId);
            } else {
                $tx = Transaction::where('transaction_id', $providerTransactionId)
                    ->where('type', $gatewayType)
                    ->lockForUpdate()
                    ->first();

                if (! $tx) {
                    throw new UnprocessableEntityHttpException('Original transaction not found for dispute event.');
                }
            }

            $intent = PaymentIntent::where('transaction_id', $tx->id)->first();
            $subscription = Subscription::where('transaction_id', $tx->id)->first();
            $expectedCurrency = strtoupper($intent?->currency ?? $currency);

            if ($disputeAmountMinor > 0 && $intent && $disputeAmountMinor > (int) $intent->amount_minor) {
                throw new UnprocessableEntityHttpException('Dispute amount exceeds original captured payment amount.');
            }

            $status = match ($lifecycleType) {
                PaymentLifecycleEvent::TYPE_DISPUTE_WON => PaymentLifecycleEvent::STATUS_WON,
                PaymentLifecycleEvent::TYPE_DISPUTE_LOST => PaymentLifecycleEvent::STATUS_LOST,
                default => PaymentLifecycleEvent::STATUS_OPEN,
            };

            $event = $this->insertLifecycleEvent([
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'provider_lifecycle_id' => $providerDisputeId,
                'provider_transaction_id' => $providerTransactionId,
                'transaction_id' => $tx->id,
                'payment_intent_id' => $intent?->id,
                'subscription_id' => $subscription?->id,
                'user_id' => $tx->user_id,
                'lifecycle_type' => $lifecycleType,
                'status' => $status,
                'amount_minor' => $disputeAmountMinor,
                'currency' => $expectedCurrency,
                'requires_admin_review' => true,
                'meta' => $meta,
                'occurred_at' => Carbon::now(),
            ]);

            return ['idempotent' => false, 'event' => $event];
        });
    }

    /**
     * N-02: Persist a durable reconciliation-required record (e.g. Stripe `refunds.has_more = true`).
     * Deduplicates on (provider, provider_charge_id, reason_code) so duplicate webhooks never
     * create duplicate unresolved records, and never marks reconciliation resolved automatically.
     *
     * @return array{created: bool, reconciliation: PaymentReconciliation}
     */
    public function recordReconciliation(
        string $provider,
        string $providerChargeId,
        string $reasonCode,
        ?string $providerPaymentIntentId = null,
        ?string $providerEventId = null,
        ?int $transactionId = null,
        ?int $paymentIntentId = null
    ): array {
        return DB::transaction(function () use (
            $provider,
            $providerChargeId,
            $reasonCode,
            $providerPaymentIntentId,
            $providerEventId,
            $transactionId,
            $paymentIntentId
        ) {
            $existing = PaymentReconciliation::where('provider', $provider)
                ->where('provider_charge_id', $providerChargeId)
                ->where('reason_code', $reasonCode)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return ['created' => false, 'reconciliation' => $existing];
            }

            try {
                $record = PaymentReconciliation::create([
                    'provider' => $provider,
                    'provider_charge_id' => $providerChargeId,
                    'provider_payment_intent_id' => $providerPaymentIntentId ?: null,
                    'provider_event_id' => $providerEventId ?: null,
                    'transaction_id' => $transactionId,
                    'payment_intent_id' => $paymentIntentId,
                    'reason_code' => $reasonCode,
                    'status' => PaymentReconciliation::STATUS_PENDING,
                    'detected_at' => Carbon::now(),
                ]);

                return ['created' => true, 'reconciliation' => $record];
            } catch (QueryException $e) {
                $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
                $driverCode = (int) ($e->errorInfo[1] ?? 0);
                if ($driverCode === 1062 || ($sqlState === '23000' && str_contains($e->getMessage(), 'Duplicate entry'))) {
                    $raced = PaymentReconciliation::where('provider', $provider)
                        ->where('provider_charge_id', $providerChargeId)
                        ->where('reason_code', $reasonCode)
                        ->firstOrFail();

                    return ['created' => false, 'reconciliation' => $raced];
                }
                throw $e;
            }
        });
    }

    private function sumRefundedMinor(?int $transactionId): int
    {
        if (! $transactionId) {
            return 0;
        }

        return (int) PaymentLifecycleEvent::where('transaction_id', $transactionId)
            ->whereIn('lifecycle_type', [
                PaymentLifecycleEvent::TYPE_FULL_REFUND,
                PaymentLifecycleEvent::TYPE_PARTIAL_REFUND,
                PaymentLifecycleEvent::TYPE_REVERSED_PAYMENT,
            ])
            ->sum('amount_minor');
    }

    private function insertLifecycleEvent(array $attributes): PaymentLifecycleEvent
    {
        try {
            return PaymentLifecycleEvent::create($attributes);
        } catch (QueryException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($driverCode === 1062 || ($sqlState === '23000' && str_contains($e->getMessage(), 'Duplicate entry'))) {
                $existing = PaymentLifecycleEvent::where('provider', $attributes['provider'])
                    ->where('provider_event_id', $attributes['provider_event_id'])
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }
            throw $e;
        }
    }
}
