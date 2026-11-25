<?php

namespace App\Services;

use App\Contracts\PaymentAlertNotifier;
use Illuminate\Support\Facades\Log;

/**
 * Safe local log-backed alert notifier (zero external network calls or credentials).
 */
class LogPaymentAlertNotifier implements PaymentAlertNotifier
{
    public function notify(string $alertKey, string $severity, string $message, array $context = []): void
    {
        $safeContext = array_merge(['alert_key' => $alertKey], $context);

        if (strtolower($severity) === 'critical' || strtolower($severity) === 'error') {
            Log::error('[PAYMENT_ALERT] ' . $message, $safeContext);

            return;
        }

        Log::warning('[PAYMENT_ALERT] ' . $message, $safeContext);
    }
}
