<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\SadminSetting;
use App\Models\Subscription;
use App\Repositories\SubscriptionRepository;
use App\Services\PaymentIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Razorpay\Api\Api;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class RazorpayPaymentController extends AppBaseController
{
    private SubscriptionRepository $subscriptionRepository;
    private PaymentIntentService $paymentIntentService;

    public function __construct(
        SubscriptionRepository $subscriptionRepository,
        ?PaymentIntentService $paymentIntentService = null
    ) {
        $this->subscriptionRepository = $subscriptionRepository;
        $this->paymentIntentService = $paymentIntentService ?? new PaymentIntentService($subscriptionRepository);
    }

    /**
     * Resolve Razorpay credentials from SadminSetting with accurate fallback to config/payment.php.
     *
     * @return array{key: string, secret: string}
     */
    private function resolveRazorpayCredentials(): array
    {
        $sadminSetting = SadminSetting::whereIn('key', ['razorpay_key', 'razorpay_secret'])
            ->pluck('value', 'key')
            ->toArray();

        $razorpayKey = ! empty($sadminSetting['razorpay_key'])
            ? (string) $sadminSetting['razorpay_key']
            : (string) config('payment.razorpay.key', '');

        $razorpaySecret = ! empty($sadminSetting['razorpay_secret'])
            ? (string) $sadminSetting['razorpay_secret']
            : (string) config('payment.razorpay.secret', '');

        return ['key' => $razorpayKey, 'secret' => $razorpaySecret];
    }

    protected function createRazorpayOrder(string $razorpayKey, string $razorpaySecret, array $orderData)
    {
        $api = new Api($razorpayKey, $razorpaySecret);

        return $api->order->create($orderData);
    }

    protected function fetchRazorpayPaymentAndOrder(string $razorpayKey, string $razorpaySecret, string $paymentId, string $orderId): array
    {
        $api = new Api($razorpayKey, $razorpaySecret);
        $payment = $api->payment->fetch($paymentId);
        $order = $api->order->fetch($orderId);

        return [$payment, $order];
    }

    public function generateSession(Request $request)
    {
        $input = $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
            'amount' => 'nullable|numeric|gt:0',
            'stores' => 'nullable|array',
            'stores.*' => 'integer',
        ]);

        $plan = Plan::with('currency')->findOrFail($input['plan_id']);
        $creds = $this->resolveRazorpayCredentials();

        if ($creds['secret'] === '' || $creds['key'] === '') {
            return $this->sendError('Razorpay Secret is not set', 422);
        }

        try {
            $intent = $this->paymentIntentService->createIntent(
                Auth::user(),
                $plan,
                Subscription::TYPE_RAZORPAY,
                $request->stores ?? null,
                'razorpay'
            );
        } catch (UnprocessableEntityHttpException $e) {
            return $this->sendError($e->getMessage(), 422);
        }

        $orderData = [
            'receipt' => $intent->reference,
            'amount' => $intent->amount_minor,
            'currency' => $intent->currency,
            'notes' => [
                'intent_ref' => $intent->reference,
                'plan_id' => (string) $intent->plan_id,
                'user_id' => (string) $intent->user_id,
                'email' => Auth::user()->email,
            ],
        ];

        try {
            $razorpayOrder = $this->createRazorpayOrder($creds['key'], $creds['secret'], $orderData);
            $orderId = is_object($razorpayOrder) ? ($razorpayOrder->id ?? '') : ($razorpayOrder['id'] ?? '');

            if ($orderId === '') {
                $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);

                return $this->sendError('Unable to initialize Razorpay order.', 422);
            }

            $this->paymentIntentService->attachProviderReference($intent, (string) $orderId);

            return $this->sendResponse([
                'key' => $creds['key'],
                'razorpayOrder' => (string) $orderId,
                'intent_ref' => $intent->reference,
                'amount' => $intent->amount_minor,
                'currency' => $intent->currency,
                'name' => Auth::user()->full_name,
                'email' => Auth::user()->email,
                'contact' => Auth::user()->phone,
                'plan_id' => $plan->id,
            ], 'Razorpay session generated successfully.');
        } catch (\Throwable $e) {
            $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);
            Log::error('Razorpay order creation failed.', [
                'intent_ref' => $intent->reference,
                'user_id' => $intent->user_id,
                'error' => $e->getMessage(),
            ]);

            return $this->sendError('Unable to initialize Razorpay order.', 422);
        }
    }

    public function paymentSuccess(Request $request)
    {
        $input = $request->all();
        try {
            $creds = $this->resolveRazorpayCredentials();
            if ($creds['secret'] === '' || $creds['key'] === '') {
                return redirect('/app/user/payment-failed?status=false');
            }

            if (empty($input['razorpay_payment_id']) || empty($input['razorpay_order_id']) || empty($input['razorpay_signature'])) {
                return redirect('/app/user/payment-failed?status=false');
            }

            $paymentId = (string) $input['razorpay_payment_id'];
            $orderId = (string) $input['razorpay_order_id'];
            $providedSig = (string) $input['razorpay_signature'];

            // Constant-time HMAC-SHA256 verification before trusting callback
            $expectedSig = hash_hmac('sha256', $orderId . '|' . $paymentId, $creds['secret']);
            if (! hash_equals($expectedSig, $providedSig)) {
                return redirect('/app/user/payment-failed?status=false');
            }

            [$payment, $razorpayOrder] = $this->fetchRazorpayPaymentAndOrder($creds['key'], $creds['secret'], $paymentId, $orderId);

            // Verify payment belongs to the exact order AND is captured
            $paymentOrderId = (string) ($payment['order_id'] ?? '');
            $fetchedOrderId = (string) ($razorpayOrder['id'] ?? $orderId);
            $paymentStatus = strtolower((string) ($payment['status'] ?? ''));

            if ($paymentOrderId !== $orderId || $fetchedOrderId !== $orderId || $paymentStatus !== 'captured') {
                return redirect('/app/user/payment-failed?status=false');
            }

            // Cross-check provider order amount & currency against payment amount & currency
            $orderAmountMinor = (int) ($razorpayOrder['amount'] ?? 0);
            $orderCurrency = strtoupper((string) ($razorpayOrder['currency'] ?? ''));
            $paymentAmountMinor = (int) ($payment['amount'] ?? 0);
            $paymentCurrency = strtoupper((string) ($payment['currency'] ?? ''));

            if ($orderAmountMinor <= 0 || $paymentAmountMinor !== $orderAmountMinor || $paymentCurrency !== $orderCurrency) {
                return redirect('/app/user/payment-failed?status=false');
            }

            // Read notes strictly from the server-created Razorpay order (never untrusted payment notes)
            $orderNotesRaw = $razorpayOrder['notes'] ?? [];
            $orderNotes = is_object($orderNotesRaw) && method_exists($orderNotesRaw, 'toArray')
                ? $orderNotesRaw->toArray()
                : (array) $orderNotesRaw;

            $intentRef = (string) ($orderNotes['intent_ref'] ?? ($razorpayOrder['receipt'] ?? ''));
            $intent = $this->paymentIntentService->findForGateway(
                Subscription::TYPE_RAZORPAY,
                $orderId,
                $intentRef !== '' ? $intentRef : null
            );

            if (! $intent) {
                Log::warning('Razorpay callback rejected: no matching server PaymentIntent.', ['order_id' => $orderId]);

                return redirect('/app/user/payment-failed?status=false');
            }

            $orderUserId = isset($orderNotes['user_id']) ? (int) $orderNotes['user_id'] : null;
            $orderPlanId = isset($orderNotes['plan_id']) ? (int) $orderNotes['plan_id'] : null;
            $metaArray = is_object($razorpayOrder) && method_exists($razorpayOrder, 'toArray')
                ? $razorpayOrder->toArray()
                : (array) $razorpayOrder;

            $this->paymentIntentService->settleIntent(
                $intent,
                $paymentId,
                $paymentAmountMinor,
                $paymentCurrency,
                $metaArray,
                $orderUserId,
                $orderPlanId,
                'razorpay'
            );

            return redirect('/app/user/payment-success?status=true');
        } catch (\Throwable $e) {
            Log::warning('Razorpay callback verification failed.', [
                'order_id' => $input['razorpay_order_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return redirect('/app/user/payment-failed?status=false');
        }
    }

    public function paymentFailed(Request $request)
    {
        // V-06: Public GET failure redirect is strictly read-only (never mutates PaymentIntent state).
        // Authoritative transitions happen via signed webhooks and PaymentIntent TTL expiration.
        return redirect('/app/user/payment-failed?status=false');
    }
}
