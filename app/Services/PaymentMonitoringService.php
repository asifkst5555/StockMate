<?php

namespace App\Services;

use App\Contracts\PaymentAlertNotifier;
use App\Models\PaymentLifecycleEvent;
use App\Models\PaymentReconciliation;
use App\Models\PaymentWebhookEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Centralized payment operational monitoring & throttled alerting service (N-04).
 * Alert failures are strictly isolated and can never interrupt payment processing.
 */
class PaymentMonitoringService
{
    private const DEFAULT_THROTTLE_SECONDS = 300;

    private PaymentAlertNotifier $notifier;

    public function __construct(?PaymentAlertNotifier $notifier = null)
    {
        $this->notifier = $notifier ?? new LogPaymentAlertNotifier();
    }

    /**
     * Dispatch a deduplicated/throttled operational alert safely (never throws).
     */
    public function dispatchAlert(
        string $alertKey,
        string $severity,
        string $message,
        array $context = [],
        ?int $throttleSeconds = null
    ): bool {
        $ttl = $throttleSeconds ?? (int) config('payment.webhook.alert_throttle_seconds', self::DEFAULT_THROTTLE_SECONDS);
        $cacheKey = 'stockmate:payment_alert:' . hash('sha256', $alertKey);

        try {
            if ($ttl > 0 && ! Cache::add($cacheKey, true, $ttl)) {
                return false; // Throttled duplicate alert
            }

            $sanitizedContext = $this->sanitizeContext($context);
            $this->notifier->notify($alertKey, $severity, $message, $sanitizedContext);

            return true;
        } catch (\Throwable $e) {
            // Never let alert delivery failures break payment or webhook processing
            Log::warning('Payment alert dispatch failed safely.', [
                'alert_key' => $alertKey,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Return safe operational review queues (never exposes secrets or raw provider payloads).
     */
    public function getReviewQueues(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));

        $deadLetters = PaymentWebhookEvent::where('status', PaymentWebhookEvent::STATUS_DEAD_LETTER)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentWebhookEvent $e) => [
                'id' => $e->id,
                'provider' => $e->provider,
                'event_id' => $e->event_id,
                'provider_reference' => $e->provider_reference,
                'event_type' => $e->event_type,
                'status' => $e->status,
                'attempts' => $e->attempts,
                'last_error' => $e->last_error,
                'dead_lettered_at' => $e->dead_lettered_at?->toIso8601String(),
                'created_at' => $e->created_at?->toIso8601String(),
                'updated_at' => $e->updated_at?->toIso8601String(),
            ])->all();

        $failedWebhooks = PaymentWebhookEvent::where('status', PaymentWebhookEvent::STATUS_FAILED)
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentWebhookEvent $e) => [
                'id' => $e->id,
                'provider' => $e->provider,
                'event_id' => $e->event_id,
                'provider_reference' => $e->provider_reference,
                'event_type' => $e->event_type,
                'status' => $e->status,
                'attempts' => $e->attempts,
                'last_error' => $e->last_error,
                'created_at' => $e->created_at?->toIso8601String(),
                'updated_at' => $e->updated_at?->toIso8601String(),
            ])->all();

        $reconciliations = PaymentReconciliation::where('status', PaymentReconciliation::STATUS_PENDING)
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentReconciliation $r) => [
                'id' => $r->id,
                'provider' => $r->provider,
                'provider_charge_id' => $r->provider_charge_id,
                'provider_payment_intent_id' => $r->provider_payment_intent_id,
                'provider_event_id' => $r->provider_event_id,
                'transaction_id' => $r->transaction_id,
                'reason_code' => $r->reason_code,
                'status' => $r->status,
                'detected_at' => $r->detected_at?->toIso8601String(),
            ])->all();

        $lifecycleReviews = PaymentLifecycleEvent::where('requires_admin_review', true)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (PaymentLifecycleEvent $l) => [
                'id' => $l->id,
                'provider' => $l->provider,
                'provider_event_id' => $l->provider_event_id,
                'provider_lifecycle_id' => $l->provider_lifecycle_id,
                'provider_transaction_id' => $l->provider_transaction_id,
                'transaction_id' => $l->transaction_id,
                'lifecycle_type' => $l->lifecycle_type,
                'status' => $l->status,
                'amount_minor' => $l->amount_minor,
                'currency' => $l->currency,
                'requires_admin_review' => $l->requires_admin_review,
                'occurred_at' => $l->occurred_at?->toIso8601String(),
            ])->all();

        return [
            'counts' => [
                'dead_letter_webhooks' => count($deadLetters),
                'failed_webhooks' => count($failedWebhooks),
                'pending_reconciliations' => count($reconciliations),
                'lifecycle_admin_reviews' => count($lifecycleReviews),
            ],
            'dead_letter_webhooks' => $deadLetters,
            'failed_webhooks' => $failedWebhooks,
            'pending_reconciliations' => $reconciliations,
            'lifecycle_admin_reviews' => $lifecycleReviews,
        ];
    }

    private function sanitizeContext(array $context): array
    {
        $blocked = ['secret', 'password', 'token', 'signature', 'authorization', 'card', 'payload', 'meta'];
        $clean = [];
        foreach ($context as $k => $v) {
            $lower = strtolower((string) $k);
            $isSensitive = false;
            foreach ($blocked as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSensitive = true;
                    break;
                }
            }
            if (! $isSensitive && (is_scalar($v) || $v === null)) {
                $clean[$k] = $v;
            }
        }

        return $clean;
    }
}
