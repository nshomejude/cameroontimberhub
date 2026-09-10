<?php

namespace App\Enums;

/**
 * Quantity unit an RFQ line item is expressed in.
 *
 * Mirrors the `rfq_items_unit_check` CHECK constraint exactly — the database is
 * the source of truth, this enum is how the app reads it.
 */
enum RfqUnit: string
{
    case CubicMetre = 'm3';
    case Ton = 'ton';
    case Piece = 'pcs';
    case Container = 'container';

    public function label(): string
    {
        return __('messages.enums.rfq_unit.'.$this->value);
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
