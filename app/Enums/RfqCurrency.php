<?php

namespace App\Enums;

/**
 * Currency an RFQ target price is quoted in.
 *
 * Mirrors the `rfqs_currency_check` CHECK constraint exactly.
 */
enum RfqCurrency: string
{
    case XAF = 'XAF';
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case CNY = 'CNY';

    public function label(): string
    {
        return match ($this) {
            self::XAF => 'XAF — Central African CFA franc',
            self::USD => 'USD — US dollar',
            self::EUR => 'EUR — Euro',
            self::GBP => 'GBP — Pound sterling',
            self::CNY => 'CNY — Chinese yuan',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
