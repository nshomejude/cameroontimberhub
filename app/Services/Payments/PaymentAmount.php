<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Money formatting / comparison at the provider boundary.
 *
 * `Payment.amount` is stored decimal(14,2) for every currency, but XAF (and
 * XOF) have no minor unit: the providers must be sent "50000", never
 * "50000.00", and Stripe's `unit_amount` for a zero-decimal currency is the
 * franc amount itself, not x100. Every gateway formats outgoing amounts
 * through here so that rule lives in one place.
 *
 * `matches()` compares a provider-confirmed amount against our Payment row.
 * Used by the mobile-money gateways whose callbacks are unauthenticated and
 * therefore re-query the provider before completing a payment.
 */
final class PaymentAmount
{
    /** Currencies with no minor unit. */
    public const ZERO_DECIMAL_CURRENCIES = ['XAF', 'XOF'];

    /** Decimal places a currency is actually settled in (XAF 0, else 2). */
    public static function precision(?string $currency): int
    {
        return in_array(strtoupper((string) $currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
    }

    /**
     * The payment amount as a decimal string at the currency's real
     * precision, half-up ("50000" for XAF, "19.99" for USD). bcmath only.
     */
    public static function forProvider(Payment $payment): string
    {
        return self::format((string) $payment->amount, (string) $payment->currency);
    }

    public static function format(string $amount, ?string $currency): string
    {
        $dp = self::precision($currency);
        $shift = bcpow('10', (string) $dp);
        $half = bccomp($amount, '0', 8) < 0 ? '-0.5' : '0.5';
        $rounded = bcdiv(bcadd(bcmul($amount, $shift, 8), $half, 8), '1', 0);

        return bcdiv($rounded, $shift, $dp);
    }

    /**
     * Integer amount in the currency's smallest unit as Stripe expects it:
     * cents for USD (19.99 -> 1999), francs for XAF (50000 -> 50000).
     */
    public static function minorUnits(Payment $payment): int
    {
        $currency = (string) $payment->currency;

        return (int) bcmul(self::format((string) $payment->amount, $currency), bcpow('10', (string) self::precision($currency)), 0);
    }

    /**
     * True when the provider's confirmed amount equals ours to the cent. A
     * missing echo is accepted (the status query itself is keyed on our
     * reference/amount); a non-numeric one is not.
     */
    public static function matches(Payment $payment, mixed $confirmed): bool
    {
        if ($confirmed === null || $confirmed === '') {
            return true;
        }

        if (! is_numeric($confirmed)) {
            return false;
        }

        return round((float) $confirmed, 2) === round((float) $payment->amount, 2);
    }
}
