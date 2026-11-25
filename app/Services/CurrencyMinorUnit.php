<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Currency-aware minor-unit conversion & formatting helper.
 * Eliminates unconditional `* 100` and floating-point equality in payment verification.
 */
class CurrencyMinorUnit
{
    /**
     * Zero-decimal ISO-4217 currencies (Stripe/Razorpay/Paystack minor unit = 1).
     */
    private const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
        'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    /**
     * PayPal-specific zero-decimal currencies (must be formatted without decimal places).
     */
    private const PAYPAL_ZERO_DECIMAL_CURRENCIES = [
        'HUF', 'JPY', 'TWD',
    ];

    /**
     * Three-decimal ISO-4217 currencies (minor unit = 1000).
     */
    private const THREE_DECIMAL_CURRENCIES = [
        'BHD', 'JOD', 'KWD', 'OMR', 'TND',
    ];

    public static function decimals(string $currency, string $provider = 'generic'): int
    {
        $code = strtoupper(trim($currency));

        if ($provider === 'paypal') {
            return in_array($code, self::PAYPAL_ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
        }

        if (in_array($code, self::ZERO_DECIMAL_CURRENCIES, true)) {
            return 0;
        }

        if (in_array($code, self::THREE_DECIMAL_CURRENCIES, true)) {
            return 3;
        }

        return 2;
    }

    public static function multiplier(string $currency, string $provider = 'generic'): int
    {
        return (int) pow(10, self::decimals($currency, $provider));
    }

    /**
     * Convert a decimal major-unit amount to exact integer minor units.
     */
    public static function toMinorUnits(float|int|string $amount, string $currency, string $provider = 'generic'): int
    {
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException('Amount must be numeric.');
        }

        $value = (float) $amount;
        if ($value < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        $multiplier = self::multiplier($currency, $provider);

        return (int) round($value * $multiplier, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Convert integer minor units back to a major-unit float for storage in transactions.amount.
     */
    public static function fromMinorUnits(int $minorUnits, string $currency, string $provider = 'generic'): float
    {
        $decimals = self::decimals($currency, $provider);
        $multiplier = self::multiplier($currency, $provider);

        return round($minorUnits / $multiplier, $decimals);
    }

    /**
     * Format a decimal amount string suitable for decimal-based providers (e.g., PayPal).
     */
    public static function formatDecimal(float|int|string $amount, string $currency, string $provider = 'paypal'): string
    {
        $decimals = self::decimals($currency, $provider);
        $minor = self::toMinorUnits($amount, $currency, $provider);
        $major = self::fromMinorUnits($minor, $currency, $provider);

        return number_format($major, $decimals, '.', '');
    }

    /**
     * Parse a provider-returned decimal amount string (e.g. PayPal "99.00") into exact integer minor units.
     */
    public static function parseDecimalToMinorUnits(string|float|int $providerAmount, string $currency, string $provider = 'paypal'): int
    {
        return self::toMinorUnits($providerAmount, $currency, $provider);
    }
}
