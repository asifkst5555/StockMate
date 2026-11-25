<?php

namespace App\Http\Controllers;

use App\Models\PaymentIntent;
use App\Models\Plan;
use App\Models\SadminSetting;
use App\Models\Subscription;
use App\Repositories\SubscriptionRepository;
use App\Services\CurrencyMinorUnit;
use App\Services\PaymentIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Srmklive\PayPal\Services\PayPal;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PaypalPaymentController extends AppBaseController
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

    protected function createPaypalOrder(array $data): array
    {
        $provider = new PayPal();
        $provider->getAccessToken();

        return (array) $provider->createOrder($data);
    }

    protected function capturePaypalOrder(string $token): array
    {
        $provider = new PayPal();
        $provider->getAccessToken();

        return (array) $provider->capturePaymentOrder($token);
    }

    private function configurePayPal(): ?array
    {
        $sadminSetting = SadminSetting::whereIn('key', ['paypal_client_id', 'paypal_secret', 'paypal_mode'])
            ->pluck('value', 'key')
            ->toArray();

        $clientId = ! empty($sadminSetting['paypal_client_id']) ? $sadminSetting['paypal_client_id'] : config('payment.paypal.client_id');
        $clientSecret = ! empty($sadminSetting['paypal_secret']) ? $sadminSetting['paypal_secret'] : config('payment.paypal.client_secret');
        $mode = ! empty($sadminSetting['paypal_mode']) ? $sadminSetting['paypal_mode'] : config('payment.paypal.mode', 'sandbox');

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        config([
            'paypal.mode'                  => $mode,
            'paypal.sandbox.client_id'     => $clientId,
            'paypal.sandbox.client_secret' => $clientSecret,
            'paypal.live.client_id'        => $clientId,
            'paypal.live.client_secret'    => $clientSecret,
        ]);

        return ['client_id' => $clientId, 'mode' => $mode];
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
        $currencyCode = strtoupper($plan->currency->code ?? 'USD');

        if (! in_array($currencyCode, getPayPalSupportedCurrencies(), true)) {
            return $this->sendError(__('messages.error.currency_not_supported_paypal'), 422);
        }

        if ($this->configurePayPal() === null) {
            return $this->sendError('PayPal credentials are not set', 422);
        }

        try {
            $intent = $this->paymentIntentService->createIntent(
                Auth::user(),
                $plan,
                Subscription::TYPE_PAYPAL,
                $request->stores ?? null,
                'paypal'
            );
        } catch (UnprocessableEntityHttpException $e) {
            return $this->sendError($e->getMessage(), 422);
        }

        $amountFormatted = CurrencyMinorUnit::formatDecimal($intent->amount_decimal, $intent->currency, 'paypal');

        $data = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'reference_id' => $intent->reference,
                    'custom_id' => $intent->reference,
                    'amount' => [
                        'value' => $amountFormatted,
                        'currency_code' => $intent->currency,
                    ],
                ],
            ],
            'application_context' => [
                'cancel_url' => route('paypal-failed', ['plan_id' => $plan->id]) . '&error=payment_cancelled',
                'return_url' => route('paypal-success'),
            ],
        ];

        try {
            $order = $this->createPaypalOrder($data);
            $orderId = (string) ($order['id'] ?? '');
            $approveUrl = $this->extractApproveUrl($order['links'] ?? []);

            if ($orderId === '' || $approveUrl === null) {
                $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);

                return $this->sendError('Unable to initialize PayPal checkout order.', 422);
            }

            $this->paymentIntentService->attachProviderReference($intent, $orderId);

            return $this->sendResponse(['url' => $approveUrl], 'Paypal session generated successfully.');
        } catch (\Throwable $e) {
            $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);
            Log::error('PayPal order creation failed.', [
                'intent_ref' => $intent->reference,
                'user_id' => $intent->user_id,
                'error' => $e->getMessage(),
            ]);

            return $this->sendError('Unable to initialize PayPal checkout order.', 422);
        }
    }

    public function paymentSuccess(Request $request)
    {
        $rawToken = (string) ($request->query('token') ?? $request->input('token', ''));
        $token = Str::after($rawToken, 'token=');
        if (empty($token)) {
            return redirect('/app/user/payment-failed?status=false');
        }

        $this->configurePayPal();

        try {
            $response = $this->capturePaypalOrder($token);

            $orderId = (string) ($response['id'] ?? '');
            $orderStatus = strtoupper((string) ($response['status'] ?? ''));
            $purchaseUnit = $response['purchase_units'][0] ?? null;
            $capture = is_array($purchaseUnit) ? ($purchaseUnit['payments']['captures'][0] ?? null) : null;

            // Strictly require COMPLETED order AND an explicit COMPLETED capture resource with ID
            if ($orderId === '' || $orderStatus !== 'COMPLETED' || ! is_array($capture)) {
                return redirect('/app/user/payment-failed?status=false');
            }

            $captureId = (string) ($capture['id'] ?? '');
            $captureStatus = strtoupper((string) ($capture['status'] ?? ''));
            if ($captureId === '' || $captureStatus !== 'COMPLETED') {
                return redirect('/app/user/payment-failed?status=false');
            }

            $actualCurrency = strtoupper((string) ($capture['amount']['currency_code'] ?? ''));
            $rawValue = $capture['amount']['value'] ?? null;
            if ($actualCurrency === '' || ! is_numeric($rawValue)) {
                return redirect('/app/user/payment-failed?status=false');
            }

            $actualAmountMinor = CurrencyMinorUnit::parseDecimalToMinorUnits($rawValue, $actualCurrency, 'paypal');

            $internalRef = (string) ($purchaseUnit['reference_id'] ?? $purchaseUnit['custom_id'] ?? $capture['custom_id'] ?? '');
            $intent = $this->paymentIntentService->findForGateway(
                Subscription::TYPE_PAYPAL,
                $orderId,
                $internalRef !== '' ? $internalRef : null
            );

            if (! $intent) {
                Log::warning('PayPal callback rejected: no matching server PaymentIntent.', ['order_id' => $orderId]);

                return redirect('/app/user/payment-failed?status=false');
            }

            $this->paymentIntentService->settleIntent(
                $intent,
                $captureId,
                $actualAmountMinor,
                $actualCurrency,
                $response,
                null,
                null,
                'paypal'
            );

            return redirect('/app/user/payment-success?status=true');
        } catch (\Throwable $e) {
            Log::warning('PayPal callback verification failed.', [
                'token' => $token,
                'error' => $e->getMessage(),
            ]);

            return redirect('/app/user/payment-failed?status=false');
        }
    }

    private function extractApproveUrl(array $links): ?string
    {
        foreach ($links as $link) {
            if (is_array($link) && strtolower((string) ($link['rel'] ?? '')) === 'approve' && ! empty($link['href'])) {
                return (string) $link['href'];
            }
        }

        return null;
    }

    public function paymentFailed(Request $request)
    {
        // V-06: Public GET failure redirect is strictly read-only (never mutates PaymentIntent state).
        // Authoritative transitions happen via signed webhooks and PaymentIntent TTL expiration.
        return redirect('/app/user/payment-failed?status=false');
    }
}
