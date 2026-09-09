<?php

namespace App\Enums;

/** Mirrors the `disputes_category_check` CHECK constraint exactly. */
enum DisputeCategory: string
{
    case Quality = 'quality';
    case Quantity = 'quantity';
    case Delay = 'delay';
    case Payment = 'payment';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Quality => 'Quality',
            self::Quantity => 'Quantity',
            self::Delay => 'Delay',
            self::Payment => 'Payment',
            self::Other => 'Other',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
