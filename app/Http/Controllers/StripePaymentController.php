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
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class StripePaymentController extends AppBaseController
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

    protected function createStripeCheckoutSession(array $payload)
    {
        return Session::create($payload);
    }

    protected function retrieveStripeSession(string $sessionId)
    {
        return Session::retrieve($sessionId);
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

        $sadminSetting = SadminSetting::whereIn('key', ['stripe_key', 'stripe_secret'])->pluck('value', 'key')->toArray();
        $stripeSecret = ! empty($sadminSetting['stripe_secret']) ? $sadminSetting['stripe_secret'] : config('services.stripe.secret');

        if (empty($stripeSecret)) {
            return $this->sendError('Stripe Secret is not set', 422);
        }

        try {
            $intent = $this->paymentIntentService->createIntent(
                Auth::user(),
                $plan,
                Subscription::TYPE_STRIPE,
                $request->stores ?? null,
                'stripe'
            );
        } catch (UnprocessableEntityHttpException $e) {
            return $this->sendError($e->getMessage(), 422);
        }

        Stripe::setApiKey($stripeSecret);

        try {
            $session = $this->createStripeCheckoutSession([
                'payment_method_types' => ['card'],
                'customer_email' => Auth::user()->email,
                'line_items' => [[
                    'price_data' => [
                        'currency' => strtolower($intent->currency),
                        'unit_amount' => $intent->amount_minor,
                        'product_data' => [
                            'name' => $plan->name,
                            'description' => $plan->description,
                        ],
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'billing_address_collection' => 'required',
                'client_reference_id' => $intent->reference,
                'metadata' => [
                    'intent_ref' => $intent->reference,
                    'user_id' => (string) $intent->user_id,
                    'plan_id' => (string) $intent->plan_id,
                ],
                // V-02: Propagate server-created intent_ref onto the underlying Stripe PaymentIntent/Charge/Dispute
                'payment_intent_data' => [
                    'metadata' => [
                        'intent_ref' => $intent->reference,
                        'user_id' => (string) $intent->user_id,
                        'plan_id' => (string) $intent->plan_id,
                    ],
                ],
                'success_url' => route('stripe-success') . '?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('stripe-failed', ['plan_id' => $plan->id]) . '&session_id={CHECKOUT_SESSION_ID}&error=payment_cancelled',
            ]);

            $sessionId = is_object($session) ? ($session->id ?? null) : ($session['id'] ?? null);
            $sessionUrl = is_object($session) ? ($session->url ?? null) : ($session['url'] ?? null);

            if (empty($sessionId) || empty($sessionUrl)) {
                $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);

                return $this->sendError('Unable to initialize Stripe checkout session.', 422);
            }

            $this->paymentIntentService->attachProviderReference($intent, (string) $sessionId);

            return $this->sendResponse(['url' => $sessionUrl], 'Stripe session generated successfully.');
        } catch (\Throwable $e) {
            $this->paymentIntentService->markTerminalFailure($intent, PaymentIntent::STATUS_FAILED);
            Log::error('Stripe session creation failed.', [
                'intent_ref' => $intent->reference,
                'user_id' => $intent->user_id,
                'error' => $e->getMessage(),
            ]);

            return $this->sendError('Unable to initialize Stripe checkout session.', 422);
        }
    }

    public function paymentSuccess(Request $request)
    {
        $sadminSetting = SadminSetting::whereIn('key', ['stripe_key', 'stripe_secret'])->pluck('value', 'key')->toArray();
        $stripeSecret = ! empty($sadminSetting['stripe_secret']) ? $sadminSetting['stripe_secret'] : config('services.stripe.secret');
        if (! empty($stripeSecret)) {
            Stripe::setApiKey($stripeSecret);
        }

        $sessionIdParam = $request->query('session_id') ?? $request->input('session_id');
        if (empty($sessionIdParam) || ! is_string($sessionIdParam)) {
            return redirect('/app/user/payment-failed?status=false');
        }

        try {
            $sessionData = $this->retrieveStripeSession($sessionIdParam);
            $sessionID = (string) ($sessionData->id ?? '');
            $paymentStatus = strtolower((string) ($sessionData->payment_status ?? ''));
            $sessionStatus = strtolower((string) ($sessionData->status ?? ''));

            if ($sessionID === '' || $sessionID !== $sessionIdParam) {
                return redirect('/app/user/payment-failed?status=false');
            }

            // Strictly require verified Stripe paid & complete status
            if ($paymentStatus !== 'paid' || $sessionStatus !== 'complete') {
                return redirect('/app/user/payment-failed?status=false');
            }

            $metadataObj = $sessionData->metadata ?? null;
            $metadata = is_object($metadataObj) ? (array) $metadataObj : (is_array($metadataObj) ? $metadataObj : []);
            $clientRef = (string) ($sessionData->client_reference_id ?? '');
            $intentRef = (string) ($metadata['intent_ref'] ?? $clientRef);

            // Must bind to an existing server-created PaymentIntent
            $intent = $this->paymentIntentService->findForGateway(
                Subscription::TYPE_STRIPE,
                $sessionID,
                $intentRef !== '' ? $intentRef : null
            );

            if (! $intent) {
                Log::warning('Stripe callback rejected: no matching server PaymentIntent.', ['session_id' => $sessionID]);

                return redirect('/app/user/payment-failed?status=false');
            }

            $providerUserId = isset($metadata['user_id']) ? (int) $metadata['user_id'] : null;
            $providerPlanId = isset($metadata['plan_id']) ? (int) $metadata['plan_id'] : null;
            $actualAmountMinor = (int) ($sessionData->amount_total ?? 0);
            $actualCurrency = (string) ($sessionData->currency ?? '');
            $metaArray = is_object($sessionData) && method_exists($sessionData, 'toArray')
                ? $sessionData->toArray()
                : (array) $sessionData;

            $stripePaymentIntentId = is_object($sessionData->payment_intent ?? null)
                ? (string) ($sessionData->payment_intent->id ?? '')
                : (string) ($sessionData->payment_intent ?? '');

            $this->paymentIntentService->settleIntent(
                $intent,
                $sessionID,
                $actualAmountMinor,
                $actualCurrency,
                $metaArray,
                $providerUserId,
                $providerPlanId,
                'stripe',
                $stripePaymentIntentId !== '' ? $stripePaymentIntentId : null
            );

            return redirect('/app/user/payment-success?status=true');
        } catch (\Throwable $e) {
            Log::warning('Stripe callback verification failed.', [
                'session_id' => $sessionIdParam,
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
