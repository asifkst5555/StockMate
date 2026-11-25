<?php

namespace App\Http\Controllers;

use App\Exceptions\WebhookOwnershipLostException;
use App\Models\PaymentIntent;
use App\Models\PaymentLifecycleEvent;
use App\Models\PaymentReconciliation;
use App\Models\PaymentWebhookEvent;
use App\Models\SadminSetting;
use App\Models\Subscription;
use App\Services\CurrencyMinorUnit;
use App\Services\PaymentIntentService;
use App\Services\PaymentLifecycleService;
use App\Services\PaymentMonitoringService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Razorpay\Api\Utility as RazorpayUtility;
use Srmklive\PayPal\Services\PayPal;
use Stripe\Webhook as StripeWebhook;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Signed asynchronous payment webhook controller for Stripe, PayPal, Razorpay, and Paystack.
 *
 * V-01/V-04: Lease-based crash recovery (`locked_until`, `processing_started_at`, `processing_token`
 * fencing token), atomic stale-lease reclaim, configurable attempt cap (`max_attempts = 10`),
 * and dead-letter handling (`dead_letter`) distinguishing permanent vs. transient failures.
 *
 * V-02/V-03: Stripe PaymentIntent correlation (`pi_*` + `payment_intent_data.metadata.intent_ref`)
 * and multi-refund iteration over `refunds.data` (plus `refund.created` / `charge.refund.updated`)
 * without ever counting cumulative `amount_refunded` as a new delta.
 */
class PaymentWebhookController extends AppBaseController
{
    private PaymentIntentService $paymentIntentService;
    private PaymentLifecycleService $lifecycleService;
    private PaymentMonitoringService $monitoringService;

    public function __construct(
        PaymentIntentService $paymentIntentService,
        ?PaymentLifecycleService $lifecycleService = null,
        ?PaymentMonitoringService $monitoringService = null
    ) {
        $this->paymentIntentService = $paymentIntentService;
        $this->lifecycleService = $lifecycleService ?? new PaymentLifecycleService();
        $this->monitoringService = $monitoringService ?? new PaymentMonitoringService();
    }

    // -------------------------------------------------------------------------
    // 1. Stripe Webhook — official \Stripe\Webhook::constructEvent()
    // -------------------------------------------------------------------------

    public function handleStripe(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature', '');
        $webhookSecret = $this->getSettingOrConfig('stripe_webhook_secret', 'payment.stripe.webhook_secret');

        if ($webhookSecret === '' || $sigHeader === '') {
            return response()->json(['message' => 'Webhook signature verification failed.'], 400);
        }

        try {
            $event = StripeWebhook::constructEvent($rawBody, $sigHeader, $webhookSecret);
        } catch (\Throwable $e) {
            Log::warning('Stripe webhook signature verification failed.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid Stripe webhook signature.'], 400);
        }

        $eventId = (string) ($event->id ?? '');
        $eventType = (string) ($event->type ?? '');
        $obj = $event->data->object ?? null;
        $providerRef = is_object($obj) ? (string) ($obj->id ?? '') : '';

        if ($eventId === '' || $eventType === '') {
            return response()->json(['message' => 'Malformed Stripe webhook event.'], 400);
        }

        $claim = $this->beginWebhookProcessing('stripe', $eventId, $eventType, $providerRef, $rawBody);
        if ($claim['action'] !== 'proceed') {
            return $this->claimRejectionResponse($claim['action']);
        }

        /** @var PaymentWebhookEvent $webhookEvent */
        $webhookEvent = $claim['event'];
        /** @var string $token */
        $token = $claim['token'];

        try {
            if (in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true) && is_object($obj)) {
                $paymentStatus = strtolower((string) ($obj->payment_status ?? ''));
                $sessionStatus = strtolower((string) ($obj->status ?? ''));
                if ($paymentStatus !== 'paid' || $sessionStatus !== 'complete') {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'ignored_unsettled'], 200);
                }

                $meta = $this->toArraySafe($obj->metadata ?? null);
                $intentRef = (string) ($meta['intent_ref'] ?? ($obj->client_reference_id ?? ''));

                $intent = $this->paymentIntentService->findForGateway(
                    Subscription::TYPE_STRIPE,
                    $providerRef !== '' ? $providerRef : null,
                    $intentRef !== '' ? $intentRef : null
                );

                if (! $intent) {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'intent_not_found'], 200);
                }

                $stripePiId = is_object($obj->payment_intent ?? null)
                    ? (string) ($obj->payment_intent->id ?? '')
                    : (string) ($obj->payment_intent ?? '');

                // N-01: Verify atomic lease & fencing-token ownership before financial side effect
                $this->assertWebhookOwnership($webhookEvent, $token);

                $this->paymentIntentService->settleIntent(
                    $intent,
                    $providerRef,
                    (int) ($obj->amount_total ?? 0),
                    (string) ($obj->currency ?? ''),
                    $this->toArraySafe($obj),
                    isset($meta['user_id']) ? (int) $meta['user_id'] : null,
                    isset($meta['plan_id']) ? (int) $meta['plan_id'] : null,
                    'stripe',
                    $stripePiId !== '' ? $stripePiId : null
                );
            } elseif (in_array($eventType, ['checkout.session.async_payment_failed', 'checkout.session.expired'], true) && $providerRef !== '') {
                $intent = $this->paymentIntentService->findForGateway(Subscription::TYPE_STRIPE, $providerRef);
                if ($intent) {
                    $this->assertWebhookOwnership($webhookEvent, $token);
                    $status = $eventType === 'checkout.session.expired'
                        ? PaymentIntent::STATUS_EXPIRED
                        : PaymentIntent::STATUS_FAILED;
                    $this->paymentIntentService->markTerminalFailure($intent, $status);
                }
            } elseif ($eventType === 'charge.refunded' && is_object($obj)) {
                $this->assertWebhookOwnership($webhookEvent, $token);
                $this->processStripeChargeRefunded($eventId, $obj);
            } elseif (in_array($eventType, ['refund.created', 'charge.refund.updated'], true) && is_object($obj)) {
                $this->assertWebhookOwnership($webhookEvent, $token);
                $this->processStripeSingleRefund($eventId, $eventType, $obj);
            } elseif (in_array($eventType, ['charge.dispute.created', 'charge.dispute.closed'], true) && is_object($obj)) {
                $meta = $this->toArraySafe($obj->metadata ?? null);
                $stripePiId = is_object($obj->payment_intent ?? null)
                    ? (string) ($obj->payment_intent->id ?? '')
                    : (string) ($obj->payment_intent ?? '');
                $intentRef = (string) ($meta['intent_ref'] ?? '');
                $fallbackSessionId = (string) ($meta['session_id'] ?? '');
                $disputeId = (string) ($obj->id ?? $eventId);
                $disputeStatus = strtolower((string) ($obj->status ?? ''));
                $lifecycleType = PaymentLifecycleEvent::TYPE_DISPUTE_OPENED;
                if ($eventType === 'charge.dispute.closed') {
                    $lifecycleType = $disputeStatus === 'won'
                        ? PaymentLifecycleEvent::TYPE_DISPUTE_WON
                        : PaymentLifecycleEvent::TYPE_DISPUTE_LOST;
                }

                $this->assertWebhookOwnership($webhookEvent, $token);

                $this->lifecycleService->recordDispute(
                    'stripe',
                    Subscription::TYPE_STRIPE,
                    $eventId,
                    $disputeId,
                    $fallbackSessionId,
                    $lifecycleType,
                    (int) ($obj->amount ?? 0),
                    strtoupper((string) ($obj->currency ?? 'USD')),
                    ['event_type' => $eventType, 'dispute_status' => $disputeStatus, 'payment_intent' => $stripePiId],
                    $stripePiId !== '' ? $stripePiId : null,
                    $intentRef !== '' ? $intentRef : null
                );
            }

            $this->markWebhookProcessed($webhookEvent, $token);

            return response()->json(['status' => 'processed'], 200);
        } catch (\Throwable $e) {
            return $this->handleWebhookException($webhookEvent, $token, 'stripe', $eventId, $eventType, $e);
        }
    }

    /**
     * V-02/V-03: Process all individual refund objects in Stripe's `charge.refunded` payload.
     * Never treats cumulative `amount_refunded` as a new delta.
     */
    private function processStripeChargeRefunded(string $eventId, object $charge): void
    {
        $meta = $this->toArraySafe($charge->metadata ?? null);
        $stripePiId = is_object($charge->payment_intent ?? null)
            ? (string) ($charge->payment_intent->id ?? '')
            : (string) ($charge->payment_intent ?? '');
        $intentRef = (string) ($meta['intent_ref'] ?? '');
        $fallbackSessionId = (string) ($meta['session_id'] ?? ($charge->checkout_session ?? ''));
        $chargeCurrency = strtoupper((string) ($charge->currency ?? ''));

        $refundsContainer = $this->toArraySafe($charge->refunds ?? null);
        $refundItems = is_array($refundsContainer['data'] ?? null) ? $refundsContainer['data'] : [];

        if (empty($refundItems)) {
            throw new UnprocessableEntityHttpException(
                'Stripe charge.refunded payload missing individual refunds.data entries; manual reconciliation required.'
            );
        }

        if (! empty($refundsContainer['has_more'])) {
            // N-02: Persist durable pending reconciliation record so paginated Stripe refunds are never silently lost
            $chargeId = (string) ($charge->id ?? $eventId);
            $resolvedTxId = null;
            $resolvedIntentId = null;
            try {
                $resolvedTx = $this->lifecycleService->resolveStripeTransaction(
                    $stripePiId !== '' ? $stripePiId : null,
                    $intentRef !== '' ? $intentRef : null,
                    $fallbackSessionId !== '' ? $fallbackSessionId : null
                );
                $resolvedTxId = $resolvedTx->id;
                $resolvedIntentId = PaymentIntent::where('transaction_id', $resolvedTx->id)->value('id');
            } catch (\Throwable $e) {
                // Still record reconciliation even if transaction lookup failed
            }

            $this->lifecycleService->recordReconciliation(
                'stripe',
                $chargeId,
                PaymentReconciliation::REASON_STRIPE_REFUNDS_PAGINATED,
                $stripePiId !== '' ? $stripePiId : null,
                $eventId,
                $resolvedTxId,
                $resolvedIntentId
            );

            $this->monitoringService->dispatchAlert(
                "stripe:reconciliation:{$chargeId}",
                'warning',
                'Stripe charge.refunded payload is paginated (has_more=true); manual reconciliation required.',
                [
                    'provider' => 'stripe',
                    'event_id' => $eventId,
                    'charge_id' => $chargeId,
                    'payment_intent_id' => $stripePiId !== '' ? $stripePiId : null,
                ]
            );
        }

        $processedCount = 0;
        $errors = [];

        foreach ($refundItems as $index => $rawItem) {
            $item = $this->toArraySafe($rawItem);
            $refundId = (string) ($item['id'] ?? '');
            $refundStatus = strtolower((string) ($item['status'] ?? 'succeeded'));
            $refundAmountMinor = (int) ($item['amount'] ?? 0);
            $refundCurrency = strtoupper((string) ($item['currency'] ?? $chargeCurrency));

            // Skip failed or canceled Stripe refunds without aborting valid refunds
            if (in_array($refundStatus, ['failed', 'canceled'], true)) {
                continue;
            }

            if ($refundId === '' || $refundAmountMinor <= 0 || $refundCurrency === '') {
                $errors[] = "Invalid refund entry at index {$index}.";
                continue;
            }

            // Unique provider_event_id per refund item when multiple refunds appear in one charge.refunded event
            $itemEventId = count($refundItems) === 1 ? $eventId : "{$eventId}:{$refundId}";

            try {
                $this->lifecycleService->recordRefund(
                    'stripe',
                    Subscription::TYPE_STRIPE,
                    $itemEventId,
                    $refundId,
                    $fallbackSessionId,
                    $refundAmountMinor,
                    $refundCurrency,
                    false,
                    ['event_type' => 'charge.refunded', 'charge_id' => $charge->id ?? null, 'payment_intent' => $stripePiId],
                    'stripe',
                    $stripePiId !== '' ? $stripePiId : null,
                    $intentRef !== '' ? $intentRef : null
                );
                $processedCount++;
            } catch (\Throwable $e) {
                Log::warning('Stripe individual refund item failed during charge.refunded batch.', [
                    'event_id' => $eventId,
                    'refund_id' => $refundId,
                    'error' => $e->getMessage(),
                ]);
                $errors[] = $e->getMessage();
            }
        }

        if (! empty($errors)) {
            throw new UnprocessableEntityHttpException(
                "Stripe refund processing encountered error(s) (processed={$processedCount}): " . implode(' | ', $errors)
            );
        }
    }

    /**
     * V-03: Handle single-refund Stripe events (`refund.created`, `charge.refund.updated`).
     */
    private function processStripeSingleRefund(string $eventId, string $eventType, object $refund): void
    {
        $refundStatus = strtolower((string) ($refund->status ?? 'succeeded'));
        if (in_array($refundStatus, ['failed', 'canceled'], true)) {
            return;
        }

        $meta = $this->toArraySafe($refund->metadata ?? null);
        $stripePiId = is_object($refund->payment_intent ?? null)
            ? (string) ($refund->payment_intent->id ?? '')
            : (string) ($refund->payment_intent ?? '');
        $intentRef = (string) ($meta['intent_ref'] ?? '');
        $fallbackSessionId = (string) ($meta['session_id'] ?? '');
        $refundId = (string) ($refund->id ?? '');
        $refundAmountMinor = (int) ($refund->amount ?? 0);
        $refundCurrency = strtoupper((string) ($refund->currency ?? ''));

        if ($refundId === '' || $refundAmountMinor <= 0 || $refundCurrency === '') {
            throw new UnprocessableEntityHttpException('Incomplete Stripe single-refund event payload.');
        }

        $this->lifecycleService->recordRefund(
            'stripe',
            Subscription::TYPE_STRIPE,
            $eventId,
            $refundId,
            $fallbackSessionId,
            $refundAmountMinor,
            $refundCurrency,
            false,
            ['event_type' => $eventType, 'payment_intent' => $stripePiId],
            'stripe',
            $stripePiId !== '' ? $stripePiId : null,
            $intentRef !== '' ? $intentRef : null
        );
    }

    // -------------------------------------------------------------------------
    // 2. Razorpay Webhook — official \Razorpay\Api\Utility::verifyWebhookSignature()
    // -------------------------------------------------------------------------

    public function handleRazorpay(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $sigHeader = (string) $request->header('X-Razorpay-Signature', '');
        $webhookSecret = $this->getSettingOrConfig('razorpay_webhook_secret', 'payment.razorpay.webhook_secret');

        if ($webhookSecret === '' || $sigHeader === '') {
            return response()->json(['message' => 'Webhook signature verification failed.'], 400);
        }

        try {
            $utility = new RazorpayUtility();
            $utility->verifyWebhookSignature($rawBody, $sigHeader, $webhookSecret);
        } catch (\Throwable $e) {
            Log::warning('Razorpay webhook signature verification failed.');

            return response()->json(['message' => 'Invalid Razorpay webhook signature.'], 400);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed Razorpay webhook payload.'], 400);
        }

        $eventType = (string) ($payload['event'] ?? '');
        $paymentEntity = $payload['payload']['payment']['entity'] ?? [];
        $orderEntity = $payload['payload']['order']['entity'] ?? [];
        $refundEntity = $payload['payload']['refund']['entity'] ?? [];
        $disputeEntity = $payload['payload']['dispute']['entity'] ?? [];

        $paymentId = (string) ($paymentEntity['id'] ?? ($refundEntity['payment_id'] ?? ($disputeEntity['payment_id'] ?? '')));
        $orderId = (string) ($paymentEntity['order_id'] ?? ($orderEntity['id'] ?? ''));

        $eventId = (string) $request->header('X-Razorpay-Event-Id', '');
        if ($eventId === '') {
            $eventId = $eventType . ':' . ($paymentId !== '' ? $paymentId : hash('sha256', $rawBody));
        }

        $claim = $this->beginWebhookProcessing('razorpay', $eventId, $eventType, $orderId !== '' ? $orderId : $paymentId, $rawBody);
        if ($claim['action'] !== 'proceed') {
            return $this->claimRejectionResponse($claim['action']);
        }

        $webhookEvent = $claim['event'];
        $token = $claim['token'];

        try {
            if (in_array($eventType, ['payment.captured', 'order.paid'], true)) {
                $status = strtolower((string) ($paymentEntity['status'] ?? ''));
                if ($paymentId === '' || $orderId === '' || $status !== 'captured') {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'ignored_unsettled'], 200);
                }

                $orderNotes = is_array($orderEntity['notes'] ?? null) ? $orderEntity['notes'] : [];
                $intentRef = (string) ($orderNotes['intent_ref'] ?? ($orderEntity['receipt'] ?? ''));

                $intent = $this->paymentIntentService->findForGateway(
                    Subscription::TYPE_RAZORPAY,
                    $orderId,
                    $intentRef !== '' ? $intentRef : null
                );

                if (! $intent) {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'intent_not_found'], 200);
                }

                if (! empty($orderEntity['amount']) && (int) $orderEntity['amount'] !== (int) ($paymentEntity['amount'] ?? 0)) {
                    throw new UnprocessableEntityHttpException('Order/payment amount mismatch.');
                }

                $this->assertWebhookOwnership($webhookEvent, $token);

                $this->paymentIntentService->settleIntent(
                    $intent,
                    $paymentId,
                    (int) ($paymentEntity['amount'] ?? 0),
                    (string) ($paymentEntity['currency'] ?? ''),
                    $payload,
                    isset($orderNotes['user_id']) ? (int) $orderNotes['user_id'] : null,
                    isset($orderNotes['plan_id']) ? (int) $orderNotes['plan_id'] : null,
                    'razorpay'
                );
            } elseif ($eventType === 'payment.failed' && $orderId !== '') {
                $intent = $this->paymentIntentService->findForGateway(Subscription::TYPE_RAZORPAY, $orderId);
                if ($intent) {
                    $this->assertWebhookOwnership($webhookEvent, $token);
                    $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);
                }
            } elseif (in_array($eventType, ['refund.created', 'refund.processed'], true)) {
                $this->assertWebhookOwnership($webhookEvent, $token);
                $refundId = (string) ($refundEntity['id'] ?? $eventId);
                $this->lifecycleService->recordRefund(
                    'razorpay',
                    Subscription::TYPE_RAZORPAY,
                    $eventId,
                    $refundId,
                    $paymentId,
                    (int) ($refundEntity['amount'] ?? 0),
                    strtoupper((string) ($refundEntity['currency'] ?? 'USD')),
                    false,
                    ['event_type' => $eventType],
                    'razorpay'
                );
            } elseif (in_array($eventType, ['payment.dispute.created', 'payment.dispute.won', 'payment.dispute.lost'], true)) {
                $disputeId = (string) ($disputeEntity['id'] ?? $eventId);
                $lifecycleType = match ($eventType) {
                    'payment.dispute.won' => PaymentLifecycleEvent::TYPE_DISPUTE_WON,
                    'payment.dispute.lost' => PaymentLifecycleEvent::TYPE_DISPUTE_LOST,
                    default => PaymentLifecycleEvent::TYPE_DISPUTE_OPENED,
                };
                $this->lifecycleService->recordDispute(
                    'razorpay',
                    Subscription::TYPE_RAZORPAY,
                    $eventId,
                    $disputeId,
                    $paymentId,
                    $lifecycleType,
                    (int) ($disputeEntity['amount'] ?? 0),
                    strtoupper((string) ($disputeEntity['currency'] ?? 'USD')),
                    ['event_type' => $eventType]
                );
            }

            $this->markWebhookProcessed($webhookEvent, $token);

            return response()->json(['status' => 'processed'], 200);
        } catch (\Throwable $e) {
            return $this->handleWebhookException($webhookEvent, $token, 'razorpay', $eventId, $eventType, $e);
        }
    }

    // -------------------------------------------------------------------------
    // 3. Paystack Webhook — official HMAC-SHA512 over raw payload
    // -------------------------------------------------------------------------

    public function handlePaystack(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $sigHeader = (string) $request->header('x-paystack-signature', '');
        $paystackSecret = $this->getSettingOrConfig('paystack_secret', 'services.paystack.secret');

        if ($paystackSecret === '' || $sigHeader === '') {
            return response()->json(['message' => 'Webhook signature verification failed.'], 400);
        }

        $expectedSig = hash_hmac('sha512', $rawBody, $paystackSecret);
        if (! hash_equals($expectedSig, $sigHeader)) {
            Log::warning('Paystack webhook signature verification failed.');

            return response()->json(['message' => 'Invalid Paystack webhook signature.'], 400);
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return response()->json(['message' => 'Malformed Paystack webhook payload.'], 400);
        }

        $eventType = (string) ($payload['event'] ?? '');
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = (string) ($data['reference'] ?? ($data['transaction_reference'] ?? ($data['transaction']['reference'] ?? '')));
        $providerTxId = (string) ($data['id'] ?? ($data['transaction']['id'] ?? $reference));
        $eventId = $eventType . ':' . ($providerTxId !== '' ? $providerTxId : hash('sha256', $rawBody));

        if ($eventType === '' || ($reference === '' && $providerTxId === '')) {
            return response()->json(['message' => 'Malformed Paystack webhook event.'], 400);
        }

        $claim = $this->beginWebhookProcessing('paystack', $eventId, $eventType, $reference !== '' ? $reference : $providerTxId, $rawBody);
        if ($claim['action'] !== 'proceed') {
            return $this->claimRejectionResponse($claim['action']);
        }

        $webhookEvent = $claim['event'];
        $token = $claim['token'];

        try {
            if ($eventType === 'charge.success') {
                if (strtolower((string) ($data['status'] ?? '')) !== 'success') {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'ignored_unsettled'], 200);
                }

                $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
                $intent = $this->paymentIntentService->findForGateway(
                    Subscription::TYPE_PAYSTACK,
                    $reference,
                    (string) ($meta['intent_ref'] ?? $reference)
                );

                if (! $intent) {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'intent_not_found'], 200);
                }

                $this->assertWebhookOwnership($webhookEvent, $token);

                $this->paymentIntentService->settleIntent(
                    $intent,
                    $providerTxId,
                    (int) ($data['amount'] ?? 0),
                    (string) ($data['currency'] ?? ''),
                    $data,
                    isset($meta['user_id']) ? (int) $meta['user_id'] : null,
                    isset($meta['plan_id']) ? (int) $meta['plan_id'] : null,
                    'paystack'
                );
            } elseif (in_array($eventType, ['charge.failed', 'charge.abandoned'], true)) {
                $intent = $this->paymentIntentService->findForGateway(Subscription::TYPE_PAYSTACK, $reference, $reference);
                if ($intent) {
                    $this->assertWebhookOwnership($webhookEvent, $token);
                    $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);
                }
            } elseif ($eventType === 'refund.processed') {
                $this->assertWebhookOwnership($webhookEvent, $token);
                $origTxId = (string) ($data['transaction']['id'] ?? ($data['transaction_id'] ?? $reference));
                $refundId = (string) ($data['id'] ?? $eventId);
                $this->lifecycleService->recordRefund(
                    'paystack',
                    Subscription::TYPE_PAYSTACK,
                    $eventId,
                    $refundId,
                    $origTxId,
                    (int) ($data['amount'] ?? 0),
                    strtoupper((string) ($data['currency'] ?? 'USD')),
                    false,
                    ['event_type' => $eventType],
                    'paystack'
                );
            } elseif (in_array($eventType, ['charge.dispute.create', 'charge.dispute.resolve'], true)) {
                $origTxId = (string) ($data['transaction']['id'] ?? ($data['transaction_id'] ?? $reference));
                $disputeId = (string) ($data['id'] ?? $eventId);
                $resolution = strtolower((string) ($data['resolution'] ?? ''));
                $lifecycleType = PaymentLifecycleEvent::TYPE_DISPUTE_OPENED;
                if ($eventType === 'charge.dispute.resolve') {
                    $lifecycleType = str_contains($resolution, 'declined') || str_contains($resolution, 'won')
                        ? PaymentLifecycleEvent::TYPE_DISPUTE_WON
                        : PaymentLifecycleEvent::TYPE_DISPUTE_LOST;
                }
                $this->lifecycleService->recordDispute(
                    'paystack',
                    Subscription::TYPE_PAYSTACK,
                    $eventId,
                    $disputeId,
                    $origTxId,
                    $lifecycleType,
                    (int) ($data['refund_amount'] ?? ($data['amount'] ?? 0)),
                    strtoupper((string) ($data['currency'] ?? 'USD')),
                    ['event_type' => $eventType, 'resolution' => $resolution]
                );
            }

            $this->markWebhookProcessed($webhookEvent, $token);

            return response()->json(['status' => 'processed'], 200);
        } catch (\Throwable $e) {
            return $this->handleWebhookException($webhookEvent, $token, 'paystack', $eventId, $eventType, $e);
        }
    }

    // -------------------------------------------------------------------------
    // 4. PayPal Webhook — official PayPal verify-webhook-signature API
    // -------------------------------------------------------------------------

    protected function verifyPaypalWebhookSignature(array $verificationData): bool
    {
        $sadminSetting = SadminSetting::whereIn('key', ['paypal_client_id', 'paypal_secret', 'paypal_mode'])
            ->pluck('value', 'key')
            ->toArray();

        $clientId = ! empty($sadminSetting['paypal_client_id']) ? $sadminSetting['paypal_client_id'] : config('payment.paypal.client_id');
        $clientSecret = ! empty($sadminSetting['paypal_secret']) ? $sadminSetting['paypal_secret'] : config('payment.paypal.client_secret');
        $mode = ! empty($sadminSetting['paypal_mode']) ? $sadminSetting['paypal_mode'] : config('payment.paypal.mode', 'sandbox');

        if (empty($clientId) || empty($clientSecret)) {
            return false;
        }

        config([
            'paypal.mode'                  => $mode,
            'paypal.sandbox.client_id'     => $clientId,
            'paypal.sandbox.client_secret' => $clientSecret,
            'paypal.live.client_id'        => $clientId,
            'paypal.live.client_secret'    => $clientSecret,
        ]);

        $provider = new PayPal();
        $provider->getAccessToken();
        $result = $provider->verifyWebHook($verificationData);

        return strtoupper((string) ($result['verification_status'] ?? '')) === 'SUCCESS';
    }

    public function handlePaypal(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $webhookId = $this->getSettingOrConfig('paypal_webhook_id', 'payment.paypal.webhook_id');

        $transmissionId = (string) $request->header('PAYPAL-TRANSMISSION-ID', '');
        $transmissionTime = (string) $request->header('PAYPAL-TRANSMISSION-TIME', '');
        $certUrl = (string) $request->header('PAYPAL-CERT-URL', '');
        $authAlgo = (string) $request->header('PAYPAL-AUTH-ALGO', '');
        $transmissionSig = (string) $request->header('PAYPAL-TRANSMISSION-SIG', '');

        $payload = json_decode($rawBody, true);
        if (
            $webhookId === '' ||
            $transmissionId === '' ||
            $transmissionTime === '' ||
            $certUrl === '' ||
            $authAlgo === '' ||
            $transmissionSig === '' ||
            ! is_array($payload)
        ) {
            return response()->json(['message' => 'Webhook signature verification failed.'], 400);
        }

        $verificationData = [
            'auth_algo' => $authAlgo,
            'cert_url' => $certUrl,
            'transmission_id' => $transmissionId,
            'transmission_sig' => $transmissionSig,
            'transmission_time' => $transmissionTime,
            'webhook_id' => $webhookId,
            'webhook_event' => $payload,
        ];

        // External verification strictly BEFORE any DB transaction or lock
        try {
            if (! $this->verifyPaypalWebhookSignature($verificationData)) {
                return response()->json(['message' => 'Invalid PayPal webhook signature.'], 400);
            }
        } catch (\Throwable $e) {
            Log::warning('PayPal webhook signature verification failed.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Invalid PayPal webhook signature.'], 400);
        }

        $eventId = (string) ($payload['id'] ?? '');
        $eventType = strtoupper((string) ($payload['event_type'] ?? ''));
        $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
        $resourceId = (string) ($resource['id'] ?? '');
        $orderId = (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');
        $customId = (string) ($resource['custom_id'] ?? '');

        if ($eventId === '' || $eventType === '') {
            return response()->json(['message' => 'Malformed PayPal webhook event.'], 400);
        }

        $claim = $this->beginWebhookProcessing('paypal', $eventId, $eventType, $orderId !== '' ? $orderId : $resourceId, $rawBody);
        if ($claim['action'] !== 'proceed') {
            return $this->claimRejectionResponse($claim['action']);
        }

        $webhookEvent = $claim['event'];
        $token = $claim['token'];

        try {
            if ($eventType === 'PAYMENT.CAPTURE.COMPLETED') {
                if (strtoupper((string) ($resource['status'] ?? '')) !== 'COMPLETED' || $resourceId === '') {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'ignored_unsettled'], 200);
                }

                $currency = strtoupper((string) ($resource['amount']['currency_code'] ?? ''));
                $value = $resource['amount']['value'] ?? null;
                if ($currency === '' || ! is_numeric($value)) {
                    throw new UnprocessableEntityHttpException('Missing PayPal capture amount.');
                }

                $intent = $this->paymentIntentService->findForGateway(
                    Subscription::TYPE_PAYPAL,
                    $orderId !== '' ? $orderId : null,
                    $customId !== '' ? $customId : null
                );

                if (! $intent) {
                    $this->markWebhookProcessed($webhookEvent, $token);

                    return response()->json(['status' => 'intent_not_found'], 200);
                }

                $amountMinor = CurrencyMinorUnit::parseDecimalToMinorUnits($value, $currency, 'paypal');

                $this->assertWebhookOwnership($webhookEvent, $token);

                $this->paymentIntentService->settleIntent(
                    $intent,
                    $resourceId,
                    $amountMinor,
                    $currency,
                    $payload,
                    null,
                    null,
                    'paypal'
                );
            } elseif (in_array($eventType, ['PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED'], true)) {
                $intent = $this->paymentIntentService->findForGateway(
                    Subscription::TYPE_PAYPAL,
                    $orderId !== '' ? $orderId : null,
                    $customId !== '' ? $customId : null
                );
                if ($intent) {
                    $this->assertWebhookOwnership($webhookEvent, $token);
                    $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);
                }
            } elseif (in_array($eventType, ['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'], true)) {
                $this->assertWebhookOwnership($webhookEvent, $token);
                $currency = strtoupper((string) ($resource['amount']['currency_code'] ?? 'USD'));
                $value = $resource['amount']['value'] ?? 0;
                $origCaptureId = (string) ($resource['supplementary_data']['related_ids']['capture_id'] ?? $resourceId);
                $this->lifecycleService->recordRefund(
                    'paypal',
                    Subscription::TYPE_PAYPAL,
                    $eventId,
                    $resourceId !== '' ? $resourceId : $eventId,
                    $origCaptureId,
                    CurrencyMinorUnit::parseDecimalToMinorUnits($value, $currency, 'paypal'),
                    $currency,
                    $eventType === 'PAYMENT.CAPTURE.REVERSED',
                    ['event_type' => $eventType],
                    'paypal'
                );
            } elseif (in_array($eventType, ['CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED'], true)) {
                $disputeId = (string) ($resource['dispute_id'] ?? $eventId);
                $origCaptureId = (string) ($resource['disputed_transactions'][0]['seller_transaction_id'] ?? '');
                $currency = strtoupper((string) ($resource['dispute_amount']['currency_code'] ?? 'USD'));
                $value = $resource['dispute_amount']['value'] ?? 0;
                $outcome = strtoupper((string) ($resource['dispute_outcome']['outcome_code'] ?? ''));
                $lifecycleType = PaymentLifecycleEvent::TYPE_DISPUTE_OPENED;
                if ($eventType === 'CUSTOMER.DISPUTE.RESOLVED') {
                    $lifecycleType = str_contains($outcome, 'SELLER')
                        ? PaymentLifecycleEvent::TYPE_DISPUTE_WON
                        : PaymentLifecycleEvent::TYPE_DISPUTE_LOST;
                }
                $this->lifecycleService->recordDispute(
                    'paypal',
                    Subscription::TYPE_PAYPAL,
                    $eventId,
                    $disputeId,
                    $origCaptureId,
                    $lifecycleType,
                    CurrencyMinorUnit::parseDecimalToMinorUnits($value, $currency, 'paypal'),
                    $currency,
                    ['event_type' => $eventType, 'outcome' => $outcome]
                );
            }

            $this->markWebhookProcessed($webhookEvent, $token);

            return response()->json(['status' => 'processed'], 200);
        } catch (\Throwable $e) {
            return $this->handleWebhookException($webhookEvent, $token, 'paypal', $eventId, $eventType, $e);
        }
    }

    // -------------------------------------------------------------------------
    // V-01 & V-04: Lease-Based Crash Recovery, Fencing Token & Dead-Letter Policy
    // -------------------------------------------------------------------------

    private function leaseSeconds(): int
    {
        return max(10, (int) config('payment.webhook.lease_seconds', 180));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('payment.webhook.max_attempts', 10));
    }

    /**
     * Atomically claim or reclaim a webhook event with a fencing token and processing lease.
     *
     * @return array{action: string, event: ?PaymentWebhookEvent, token: ?string}
     */
    protected function beginWebhookProcessing(
        string $provider,
        string $eventId,
        string $eventType,
        ?string $providerReference,
        string $rawBody
    ): array {
        $now = Carbon::now();
        $leaseUntil = $now->copy()->addSeconds($this->leaseSeconds());
        $maxAttempts = $this->maxAttempts();
        $token = bin2hex(random_bytes(24));

        try {
            return DB::transaction(function () use (
                $provider,
                $eventId,
                $eventType,
                $providerReference,
                $rawBody,
                $now,
                $leaseUntil,
                $maxAttempts,
                $token
            ) {
                $existing = PaymentWebhookEvent::where('provider', $provider)
                    ->where('event_id', $eventId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->status === PaymentWebhookEvent::STATUS_PROCESSED) {
                        return ['action' => 'duplicate', 'event' => $existing, 'token' => null];
                    }

                    if ($existing->status === PaymentWebhookEvent::STATUS_DEAD_LETTER) {
                        return ['action' => 'dead_letter', 'event' => $existing, 'token' => null];
                    }

                    // Active, unexpired lease owned by another worker -> do not run a duplicate worker
                    if ($existing->hasActiveLease($now)) {
                        return ['action' => 'in_flight', 'event' => $existing, 'token' => null];
                    }

                    // Reclaimable states: expired processing lease, failed, or received
                    if (((int) $existing->attempts) >= $maxAttempts) {
                        $existing->update([
                            'status' => PaymentWebhookEvent::STATUS_DEAD_LETTER,
                            'locked_until' => null,
                            'processing_token' => null,
                            'dead_lettered_at' => $now,
                            'last_error' => $existing->last_error ?: 'Maximum webhook processing attempts reached.',
                        ]);

                        Log::error('Payment webhook dead-lettered after reaching max attempts.', [
                            'provider' => $provider,
                            'event_id' => $eventId,
                            'event_type' => $eventType,
                            'attempts' => $existing->attempts,
                        ]);

                        return ['action' => 'dead_letter', 'event' => $existing->refresh(), 'token' => null];
                    }

                    $wasStale = $existing->status === PaymentWebhookEvent::STATUS_PROCESSING;
                    $existing->update([
                        'status' => PaymentWebhookEvent::STATUS_PROCESSING,
                        'processing_started_at' => $now,
                        'locked_until' => $leaseUntil,
                        'processing_token' => $token,
                        'attempts' => ((int) $existing->attempts) + 1,
                        'last_error' => null,
                    ]);

                    if ($wasStale) {
                        Log::warning('Reclaimed expired webhook processing lease.', [
                            'provider' => $provider,
                            'event_id' => $eventId,
                            'event_type' => $eventType,
                            'attempts' => $existing->attempts,
                        ]);
                    }

                    return ['action' => 'proceed', 'event' => $existing->refresh(), 'token' => $token];
                }

                $created = PaymentWebhookEvent::create([
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'provider_reference' => $providerReference ?: null,
                    'status' => PaymentWebhookEvent::STATUS_PROCESSING,
                    'processing_started_at' => $now,
                    'locked_until' => $leaseUntil,
                    'processing_token' => $token,
                    'attempts' => 1,
                    'last_error' => null,
                    'payload_hash' => hash('sha256', $rawBody),
                    'processed_at' => null,
                    'dead_lettered_at' => null,
                ]);

                return ['action' => 'proceed', 'event' => $created, 'token' => $token];
            });
        } catch (QueryException $e) {
            // Concurrent first-arrival race hit UNIQUE(provider, event_id)
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($driverCode === 1062 || ($sqlState === '23000' && str_contains($e->getMessage(), 'Duplicate entry'))) {
                return ['action' => 'in_flight', 'event' => null, 'token' => null];
            }
            throw $e;
        }
    }

    /**
     * N-01: Atomically verify that this worker still owns an active processing lease
     * with the matching fencing token right before performing any financial side effect.
     *
     * @throws WebhookOwnershipLostException
     */
    protected function assertWebhookOwnership(PaymentWebhookEvent $event, string $token): void
    {
        $now = Carbon::now();

        $ownsLease = DB::transaction(function () use ($event, $token, $now) {
            $locked = PaymentWebhookEvent::whereKey($event->id)
                ->lockForUpdate()
                ->first();

            return $locked !== null
                && $locked->status === PaymentWebhookEvent::STATUS_PROCESSING
                && is_string($locked->processing_token)
                && hash_equals($locked->processing_token, $token)
                && $locked->hasActiveLease($now);
        });

        if (! $ownsLease) {
            throw new WebhookOwnershipLostException(
                "Webhook worker lost lease ownership for {$event->provider}:{$event->event_id}."
            );
        }
    }

    /**
     * Fenced completion update: only the worker still holding `processing_token` may mark PROCESSED.
     */
    protected function markWebhookProcessed(PaymentWebhookEvent $event, string $token): bool
    {
        $updated = PaymentWebhookEvent::whereKey($event->id)
            ->where('status', PaymentWebhookEvent::STATUS_PROCESSING)
            ->where('processing_token', $token)
            ->update([
                'status' => PaymentWebhookEvent::STATUS_PROCESSED,
                'processed_at' => Carbon::now(),
                'locked_until' => null,
                'processing_token' => null,
                'last_error' => null,
                'updated_at' => Carbon::now(),
            ]);

        if ($updated === 0) {
            Log::warning('Stale webhook worker skipped PROCESSED transition due to fencing token mismatch.', [
                'provider' => $event->provider,
                'event_id' => $event->event_id,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Fenced failure/dead-letter transition: only the worker holding `processing_token` may update state.
     */
    protected function markWebhookFailed(
        PaymentWebhookEvent $event,
        string $token,
        string $errorMessage,
        bool $isPermanent = false
    ): string {
        $maxAttempts = $this->maxAttempts();
        $targetStatus = ($isPermanent || ((int) $event->attempts) >= $maxAttempts)
            ? PaymentWebhookEvent::STATUS_DEAD_LETTER
            : PaymentWebhookEvent::STATUS_FAILED;

        $payload = [
            'status' => $targetStatus,
            'locked_until' => null,
            'processing_token' => null,
            'last_error' => Str::limit($errorMessage, 500),
            'updated_at' => Carbon::now(),
        ];

        if ($targetStatus === PaymentWebhookEvent::STATUS_DEAD_LETTER) {
            $payload['dead_lettered_at'] = Carbon::now();
        }

        $updated = PaymentWebhookEvent::whereKey($event->id)
            ->where('status', PaymentWebhookEvent::STATUS_PROCESSING)
            ->where('processing_token', $token)
            ->update($payload);

        if ($updated === 0) {
            Log::warning('Stale webhook worker skipped FAILED/DEAD_LETTER transition due to fencing token mismatch.', [
                'provider' => $event->provider,
                'event_id' => $event->event_id,
            ]);

            return 'stale_ignored';
        }

        return $targetStatus;
    }

    private function handleWebhookException(
        PaymentWebhookEvent $webhookEvent,
        string $token,
        string $provider,
        string $eventId,
        string $eventType,
        \Throwable $e
    ): JsonResponse {
        // N-01: Stale worker lost lease ownership -> abort cleanly without altering newer worker's state
        if ($e instanceof WebhookOwnershipLostException) {
            Log::warning('Stale webhook worker aborted before financial side effect.', [
                'provider' => $provider,
                'event_id' => $eventId,
                'event_type' => $eventType,
            ]);

            return response()->json([
                'status' => 'stale_worker_aborted',
                'message' => 'Webhook lease ownership lost; side effect aborted.',
            ], 200);
        }

        $isPermanent = $e instanceof UnprocessableEntityHttpException;
        $resultingStatus = $this->markWebhookFailed($webhookEvent, $token, $e->getMessage(), $isPermanent);

        if ($resultingStatus === 'stale_ignored') {
            return response()->json([
                'status' => 'stale_worker_aborted',
                'message' => 'Webhook lease ownership lost; state preserved.',
            ], 200);
        }

        if ($resultingStatus === PaymentWebhookEvent::STATUS_DEAD_LETTER) {
            $this->monitoringService->dispatchAlert(
                "{$provider}:dead_letter:{$eventId}",
                'error',
                'Payment webhook moved to dead-letter queue.',
                [
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'attempts' => $webhookEvent->attempts,
                    'permanent' => $isPermanent,
                    'error' => Str::limit($e->getMessage(), 300),
                ]
            );

            // N-03: Acknowledge durably recorded dead-letter with HTTP 200 to stop provider retry storms
            return response()->json([
                'status' => 'dead_letter_recorded',
                'message' => 'Webhook processing permanently failed and was durably recorded in dead-letter state.',
            ], 200);
        }

        Log::warning('Payment webhook transient failure; retryable.', [
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'attempts' => $webhookEvent->attempts,
            'error' => Str::limit($e->getMessage(), 300),
        ]);

        return response()->json([
            'status' => 'retryable_error',
            'message' => 'Webhook processing failed transiently; retryable.',
        ], 500);
    }

    private function claimRejectionResponse(string $action): JsonResponse
    {
        if ($action === 'dead_letter') {
            // N-03: Durably dead-lettered event acknowledged with HTTP 200 to prevent infinite provider retries
            return response()->json(['status' => 'dead_letter_recorded'], 200);
        }

        if ($action === 'in_flight') {
            return response()->json(['status' => 'in_flight'], 200);
        }

        return response()->json(['status' => 'duplicate_ignored'], 200);
    }

    private function toArraySafe($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return method_exists($value, 'toArray') ? $value->toArray() : (array) $value;
        }

        return [];
    }

    private function getSettingOrConfig(string $settingKey, string $configKey): string
    {
        $row = SadminSetting::where('key', $settingKey)->first();
        if ($row && ! empty($row->value)) {
            return (string) $row->value;
        }

        return (string) config($configKey, '');
    }
}
