<?php

namespace App\Support;

use BackedEnum;

/**
 * Currency-aware decimal rounding for trade money (bcmath strings, no floats).
 *
 * XAF / XOF have no minor unit in practice, so every quote/order line is
 * rounded to whole francs; every other currency to cents. Rounding is
 * half-up (away from zero). Results are expressed at 2dp so they drop
 * straight into the `decimal(…, 2)` money columns ("25003.00").
 */
final class Money
{
    /** Currencies settled in whole units. */
    public const ZERO_DECIMAL = ['XAF', 'XOF'];

    public static function precision(BackedEnum|string|null $currency): int
    {
        $code = $currency instanceof BackedEnum ? (string) $currency->value : (string) $currency;

        return in_array(strtoupper($code), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** Round a decimal string half-up to $precision places. */
    public static function roundHalfUp(string $number, int $precision): string
    {
        $negative = str_starts_with($number, '-');
        $abs = ltrim($number, '-');
        $increment = '0.'.str_repeat('0', $precision).'5';
        $rounded = bcadd($abs, $precision > 0 ? $increment : '0.5', $precision);

        return $negative && bccomp($rounded, '0', $precision) !== 0 ? '-'.$rounded : $rounded;
    }

    /** Round to the currency's precision, expressed at 2dp. */
    public static function forCurrency(string $number, BackedEnum|string|null $currency): string
    {
        return bcadd(self::roundHalfUp($number, self::precision($currency)), '0', 2);
    }
}
