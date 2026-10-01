<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * Compares a provider-confirmed amount against our Payment row. Used by the
 * mobile-money gateways whose callbacks are unauthenticated and therefore
 * re-query the provider before completing a payment.
 */
final class PaymentAmount
{
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
