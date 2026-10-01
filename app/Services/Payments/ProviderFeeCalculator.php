<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * Pure, deterministic payment-provider fee math (PayPal commission
 * structure, part A). No config, DB or HTTP — every input is a parameter, so
 * the numbers are trivially testable. App\Services\Payments\ProviderFees
 * resolves the per-provider inputs and calls this.
 *
 * Two bearers (config/payments.php `fee_bearer`):
 *
 *  - 'buyer' (passed through) — gross the charge up so that, after the
 *    provider takes `percent` of the total plus `fixed`, the platform nets
 *    exactly the list price:
 *
 *        total = (price + fixed) / (1 - percent/100)   rounded UP to the
 *                                                      currency's precision
 *        fee   = total - price
 *
 *    Rounding up (never half-up) guarantees the net is never short by a
 *    cent. The payer is shown subtotal / fee / total before authorising.
 *
 *  - 'platform' (absorbed) — the payer is charged the list price; the fee
 *    the provider will deduct (price * percent/100 + fixed, half-up) is
 *    recorded as a platform cost for the finance report.
 *
 * ── Money representation ────────────────────────────────────────────────
 * Same convention as App\Services\Commission\CommissionCalculator: bcmath
 * decimal strings only, currency precision XAF/XOF = 0dp, everything else
 * 2dp, every amount re-expressed at 2dp for the decimal(…, 2) columns.
 */
final class ProviderFeeCalculator
{
    public const BEARER_BUYER = 'buyer';

    public const BEARER_PLATFORM = 'platform';

    /**
     * @param  string  $percent  the provider percentage as a PERCENT ("4.4" = 4.4%)
     * @param  string  $fixed  the fixed fee, in $currency
     * @return array{base: string, fee: string, total: string, bearer: string, passed_through: bool, percent: string, fixed: string, currency: string}
     */
    public function calculate(string $price, string $currency, string $percent, string $fixed, string $bearer): array
    {
        if (! in_array($bearer, [self::BEARER_BUYER, self::BEARER_PLATFORM], true)) {
            throw new InvalidArgumentException("Unknown provider fee bearer [{$bearer}] — expected 'buyer' or 'platform'.");
        }

        $currency = strtoupper($currency);
        $precision = self::precisionFor($currency);
        $price = bcadd($price, '0', 2);
        $percent = bcadd($percent, '0', 4);
        $fixed = bcadd($fixed, '0', 2);

        if (bccomp($percent, '0', 4) < 0 || bccomp($percent, '100', 4) >= 0 || bccomp($fixed, '0', 2) < 0) {
            throw new InvalidArgumentException('A provider fee percent must be in [0, 100) and the fixed fee must not be negative.');
        }

        $noFee = bccomp($percent, '0', 4) === 0 && bccomp($fixed, '0', 2) === 0;

        // Nothing to charge (free plan) or no fee configured: total = price.
        if (bccomp($price, '0', 2) <= 0 || $noFee) {
            return $this->result($price, '0.00', $price, $bearer, $percent, $fixed, $currency);
        }

        $rate = bcdiv($percent, '100', 8);

        if ($bearer === self::BEARER_BUYER) {
            $exact = bcdiv(bcadd($price, $fixed, 12), bcsub('1', $rate, 12), 12);
            $total = bcadd(self::ceil($exact, $precision), '0', 2);
            $fee = bcsub($total, $price, 2);

            return $this->result($price, $fee, $total, $bearer, $percent, $fixed, $currency);
        }

        $fee = bcadd(self::roundHalfUp(bcadd(bcmul($price, $rate, 12), $fixed, 12), $precision), '0', 2);

        return $this->result($price, $fee, $price, $bearer, $percent, $fixed, $currency);
    }

    /** XAF / XOF are zero-decimal; every other currency is 2dp. */
    public static function precisionFor(string $currency): int
    {
        return in_array(strtoupper($currency), ['XAF', 'XOF'], true) ? 0 : 2;
    }

    /** "1,234.50 USD" / "50,000 XAF" — display at the currency's precision. */
    public static function format(float|string|null $amount, string $currency): string
    {
        return number_format((float) ($amount ?? 0), self::precisionFor($currency)).' '.strtoupper($currency);
    }

    /** @return array{base: string, fee: string, total: string, bearer: string, passed_through: bool, percent: string, fixed: string, currency: string} */
    private function result(string $base, string $fee, string $total, string $bearer, string $percent, string $fixed, string $currency): array
    {
        return [
            'base' => $base,
            'fee' => $fee,
            'total' => $total,
            'bearer' => $bearer,
            // True only when the payer actually pays a fee line on top.
            'passed_through' => $bearer === self::BEARER_BUYER && bccomp($fee, '0', 2) > 0,
            'percent' => str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent,
            'fixed' => $fixed,
            'currency' => $currency,
        ];
    }

    /** Ceiling of a non-negative bcmath decimal string at $precision. */
    private static function ceil(string $number, int $precision): string
    {
        $truncated = bcadd($number, '0', $precision);

        if (bccomp($truncated, $number, 12) < 0) {
            $truncated = bcadd($truncated, $precision > 0 ? '0.'.str_repeat('0', $precision - 1).'1' : '1', $precision);
        }

        return $truncated;
    }

    /** Half-up rounding of a non-negative bcmath decimal string. */
    private static function roundHalfUp(string $number, int $precision): string
    {
        $half = $precision > 0 ? '0.'.str_repeat('0', $precision).'5' : '0.5';

        return bcadd(bcadd($number, $half, 12), '0', $precision);
    }
}
