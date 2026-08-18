<?php

namespace App\Enums;

/**
 * Unit a catalogue price (and a minimum order quantity) is quoted in.
 */
enum PriceUnit: string
{
    case CubicMetre = 'm3';
    case SquareMetre = 'm2';
    case Piece = 'pcs';
    case Ton = 'ton';

    public function label(): string
    {
        return match ($this) {
            self::CubicMetre => 'm³',
            self::SquareMetre => 'm²',
            self::Piece => 'pcs',
            self::Ton => 'ton',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
