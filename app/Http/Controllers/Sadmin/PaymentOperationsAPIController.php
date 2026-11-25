<?php

namespace App\Http\Controllers\Sadmin;

use App\Http\Controllers\AppBaseController;
use App\Models\PaymentLifecycleEvent;
use App\Models\PaymentReconciliation;
use App\Models\PaymentRecoveryAudit;
use App\Models\PaymentWebhookEvent;
use App\Services\PaymentMonitoringService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin Payment Operational Review & Auditable Recovery API (N-04).
 *
 * Security invariants:
 * - Strictly restricted to `role:superadmin` via routes/sadmin.php.
 * - Exposes only safe metadata fields (never secrets or raw provider payloads).
 * - Requires explicit `confirm: true` and audit `reason` on every mutation.
 * - Never marks a payment paid or bypasses signature/idempotency verification.
 * - Rejects recovery while another worker owns an active processing lease.
 */
class PaymentOperationsAPIController extends AppBaseController
{
    private PaymentMonitoringService $monitoringService;

    public function __construct(?PaymentMonitoringService $monitoringService = null)
    {
        $this->monitoringService = $monitoringService ?? new PaymentMonitoringService();
    }

    public function reviewQueue(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 50);

        return $this->sendResponse(
            $this->monitoringService->getReviewQueues($limit),
            'Payment operational review queue retrieved successfully.'
        );
    }

    /**
     * Prepare a dead-lettered webhook event for controlled signed retry without bypassing verification.
     */
    public function requeueWebhook(Request $request, PaymentWebhookEvent $webhookEvent): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => 'required|boolean|accepted',
            'reason' => 'required|string|min:5|max:500',
        ]);

        $result = DB::transaction(function () use ($webhookEvent, $validated) {
            $locked = PaymentWebhookEvent::whereKey($webhookEvent->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentWebhookEvent::STATUS_PROCESSED) {
                return ['error' => 'Cannot requeue an already processed webhook event.', 'code' => 422];
            }

            if ($locked->hasActiveLease()) {
                return ['error' => 'Cannot requeue webhook event while another worker holds an active lease.', 'code' => 409];
            }

            if ($locked->status !== PaymentWebhookEvent::STATUS_DEAD_LETTER) {
                return ['error' => 'Only dead-letter webhook events can be requeued through this workflow.', 'code' => 422];
            }

            $previousStatus = $locked->status;
            $maxAttempts = max(1, (int) config('payment.webhook.max_attempts', 10));

            // Allow one controlled signed redelivery through the normal signature-verified webhook endpoint
            $locked->update([
                'status' => PaymentWebhookEvent::STATUS_FAILED,
                'attempts' => max(0, $maxAttempts - 1),
                'locked_until' => null,
                'processing_token' => null,
                'dead_lettered_at' => null,
            ]);

            $audit = PaymentRecoveryAudit::create([
                'actor_user_id' => Auth::id(),
                'target_type' => PaymentRecoveryAudit::TARGET_WEBHOOK_EVENT,
                'target_id' => $locked->id,
                'action' => 'prepare_retry',
                'reason' => $validated['reason'],
                'previous_status' => $previousStatus,
                'new_status' => PaymentWebhookEvent::STATUS_FAILED,
            ]);

            return ['event' => $locked->refresh(), 'audit_id' => $audit->id];
        });

        if (isset($result['error'])) {
            return $this->sendError($result['error'], $result['code']);
        }

        return $this->sendResponse([
            'id' => $result['event']->id,
            'status' => $result['event']->status,
            'audit_id' => $result['audit_id'],
        ], 'Dead-letter webhook event prepared for signed redelivery.');
    }

    /**
     * Mark a pending refund reconciliation record as resolved with full audit trail.
     */
    public function resolveReconciliation(Request $request, PaymentReconciliation $reconciliation): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => 'required|boolean|accepted',
            'reason' => 'required|string|min:5|max:500',
        ]);

        $result = DB::transaction(function () use ($reconciliation, $validated) {
            $locked = PaymentReconciliation::whereKey($reconciliation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === PaymentReconciliation::STATUS_RESOLVED) {
                return ['error' => 'Reconciliation record is already resolved.', 'code' => 422];
            }

            $previousStatus = $locked->status;
            $locked->update([
                'status' => PaymentReconciliation::STATUS_RESOLVED,
                'resolved_at' => Carbon::now(),
                'resolved_by' => Auth::id(),
                'resolution_notes' => $validated['reason'],
            ]);

            $audit = PaymentRecoveryAudit::create([
                'actor_user_id' => Auth::id(),
                'target_type' => PaymentRecoveryAudit::TARGET_RECONCILIATION,
                'target_id' => $locked->id,
                'action' => 'mark_resolved',
                'reason' => $validated['reason'],
                'previous_status' => $previousStatus,
                'new_status' => PaymentReconciliation::STATUS_RESOLVED,
            ]);

            return ['reconciliation' => $locked->refresh(), 'audit_id' => $audit->id];
        });

        if (isset($result['error'])) {
            return $this->sendError($result['error'], $result['code']);
        }

        return $this->sendResponse([
            'id' => $result['reconciliation']->id,
            'status' => $result['reconciliation']->status,
            'audit_id' => $result['audit_id'],
        ], 'Payment reconciliation marked as resolved.');
    }

    /**
     * Mark a refund/dispute lifecycle event as reviewed by Super Admin with audit trail.
     */
    public function markLifecycleReviewed(Request $request, PaymentLifecycleEvent $lifecycleEvent): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => 'required|boolean|accepted',
            'reason' => 'required|string|min:5|max:500',
        ]);

        $result = DB::transaction(function () use ($lifecycleEvent, $validated) {
            $locked = PaymentLifecycleEvent::whereKey($lifecycleEvent->id)->lockForUpdate()->firstOrFail();
            if (! $locked->requires_admin_review) {
                return ['error' => 'Lifecycle event has already been reviewed.', 'code' => 422];
            }

            $locked->update([
                'requires_admin_review' => false,
                'reviewed_at' => Carbon::now(),
            ]);

            $audit = PaymentRecoveryAudit::create([
                'actor_user_id' => Auth::id(),
                'target_type' => PaymentRecoveryAudit::TARGET_LIFECYCLE_EVENT,
                'target_id' => $locked->id,
                'action' => 'mark_reviewed',
                'reason' => $validated['reason'],
                'previous_status' => 'requires_review',
                'new_status' => 'reviewed',
            ]);

            return ['event' => $locked->refresh(), 'audit_id' => $audit->id];
        });

        if (isset($result['error'])) {
            return $this->sendError($result['error'], $result['code']);
        }

        return $this->sendResponse([
            'id' => $result['event']->id,
            'requires_admin_review' => $result['event']->requires_admin_review,
            'audit_id' => $result['audit_id'],
        ], 'Payment lifecycle event marked as reviewed.');
    }
}
