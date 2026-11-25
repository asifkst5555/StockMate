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
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Yabacon\Paystack;

class PaystackPaymentController extends AppBaseController
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
     * Resolve Paystack secret from SadminSetting with accurate fallback to config/services.php.
     */
    private function resolvePaystackSecret(): string
    {
        $sadminSetting = SadminSetting::whereIn('key', ['paystack_key', 'paystack_secret'])
            ->pluck('value', 'key')
            ->toArray();

        return ! empty($sadminSetting['paystack_secret'])
            ? (string) $sadminSetting['paystack_secret']
            : (string) config('services.paystack.secret', '');
    }

    protected function initializePaystackTransaction(string $paystackSecret, array $payload)
    {
        $paystack = new Paystack($paystackSecret);

        return $paystack->transaction->initialize($payload);
    }

    protected function verifyPaystackTransaction(string $paystackSecret, string $reference)
    {
        $paystack = new Paystack($paystackSecret);

        return $paystack->transaction->verify([
            'reference' => $reference,
        ]);
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
        $paystackSecret = $this->resolvePaystackSecret();

        if ($paystackSecret === '') {
            return $this->sendError('Paystack Secret is not set', 422);
        }

        try {
            $intent = $this->paymentIntentService->createIntent(
                Auth::user(),
                $plan,
                Subscription::TYPE_PAYSTACK,
                $request->stores ?? null,
                'paystack'
            );
        } catch (UnprocessableEntityHttpException $e) {
            return $this->sendError($e->getMessage(), 422);
        }

        // Bind Paystack transaction reference directly to server-side PaymentIntent reference
        $this->paymentIntentService->attachProviderReference($intent, $intent->reference);

        try {
            $tranx = $this->initializePaystackTransaction($paystackSecret, [
                'email' => Auth::user()->email,
                'amount' => $intent->amount_minor,
                'currency' => $intent->currency,
                'reference' => $intent->reference,
                'callback_url' => route('paystack-success'),
                'metadata' => [
                    'intent_ref' => $intent->reference,
                    'user_id' => $intent->user_id,
                    'plan_id' => $intent->plan_id,
                    'referrer' => url()->previous(),
                ],
            ]);

            $authUrl = $tranx->data->authorization_url ?? null;
            if (empty($authUrl)) {
                $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);

                return $this->sendError('Unable to initialize Paystack payment session.', 422);
            }

            return $this->sendResponse(['url' => $authUrl], 'Paystack payment link generated successfully.');
        } catch (\Throwable $e) {
            $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);
            Log::error('Paystack transaction initialization failed.', [
                'intent_ref' => $intent->reference,
                'user_id' => $intent->user_id,
                'error' => $e->getMessage(),
            ]);

            return $this->sendError('Unable to initialize Paystack payment session.', 422);
        }
    }

    public function paymentSuccess(Request $request)
    {
        $reference = $request->query('reference');
        if (empty($reference) || ! is_string($reference)) {
            return redirect('/app/user/payment-failed?status=false');
        }

        $paystackSecret = $this->resolvePaystackSecret();
        if ($paystackSecret === '') {
            return redirect('/app/user/payment-failed?status=false');
        }

        try {
            $tranx = $this->verifyPaystackTransaction($paystackSecret, $reference);
            $data = $tranx->data ?? null;

            if (empty($tranx->status) || ! is_object($data) || strtolower((string) ($data->status ?? '')) !== 'success') {
                return redirect('/app/user/payment-failed?status=false');
            }

            // Verify returned reference matches requested reference
            $returnedRef = (string) ($data->reference ?? '');
            if ($returnedRef !== '' && $returnedRef !== $reference) {
                return redirect('/app/user/payment-failed?status=false');
            }

            $providerTxId = (string) ($data->id ?? $reference);
            if ($providerTxId === '') {
                return redirect('/app/user/payment-failed?status=false');
            }

            $metaData = (array) ($data->metadata ?? []);
            $intentRef = (string) ($metaData['intent_ref'] ?? $reference);

            $intent = $this->paymentIntentService->findForGateway(
                Subscription::TYPE_PAYSTACK,
                $reference,
                $intentRef !== '' ? $intentRef : null
            );

            if (! $intent) {
                Log::warning('Paystack callback rejected: no matching server PaymentIntent.', ['reference' => $reference]);

                return redirect('/app/user/payment-failed?status=false');
            }

            $metaUserId = isset($metaData['user_id']) ? (int) $metaData['user_id'] : null;
            $metaPlanId = isset($metaData['plan_id']) ? (int) $metaData['plan_id'] : null;
            $actualAmountMinor = (int) ($data->amount ?? 0);
            $actualCurrency = (string) ($data->currency ?? '');

            $this->paymentIntentService->settleIntent(
                $intent,
                $providerTxId,
                $actualAmountMinor,
                $actualCurrency,
                (array) $data,
                $metaUserId,
                $metaPlanId,
                'paystack'
            );

            return redirect('/app/user/payment-success?status=true');
        } catch (\Throwable $e) {
            Log::warning('Paystack callback verification failed.', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return redirect('/app/user/payment-failed?status=false');
        }
    }
}
