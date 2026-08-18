<?php

namespace App\Enums;

/**
 * Catalogue product type — the processing category a marketplace listing
 * belongs to. Drives the "Product Type" row on the product detail page and
 * the type facet on /marketplace.
 */
enum ProductType: string
{
    case SawnTimber = 'sawn_timber';
    case Logs = 'logs';
    case Veneer = 'veneer';
    case Flooring = 'flooring';
    case Decking = 'decking';
    case Mouldings = 'mouldings';
    case Plywood = 'plywood';
    case Beams = 'beams';

    public function label(): string
    {
        return match ($this) {
            self::SawnTimber => 'Sawn Timber',
            self::Logs => 'Logs',
            self::Veneer => 'Veneer',
            self::Flooring => 'Flooring',
            self::Decking => 'Decking',
            self::Mouldings => 'Mouldings',
            self::Plywood => 'Plywood',
            self::Beams => 'Beams',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::SawnTimber, self::Beams => 'success',
            self::Logs => 'warning',
            self::Veneer, self::Plywood => 'info',
            self::Flooring, self::Decking, self::Mouldings => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
