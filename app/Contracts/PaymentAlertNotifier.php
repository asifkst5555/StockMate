<?php

namespace App\Contracts;

/**
 * Configurable payment operational alert notifier interface (N-04).
 * Default local implementation logs safely; future production channels (email/Slack/webhook)
 * can bind this contract without modifying payment processing logic.
 */
interface PaymentAlertNotifier
{
    public function notify(string $alertKey, string $severity, string $message, array $context = []): void;
}
