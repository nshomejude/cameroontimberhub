<?php

namespace App\Enums;

/**
 * Commercial grouping used by the Cameroonian timber trade to bucket species
 * by market position (price band, volume, promotion status).
 *
 * IMPORTANT: this is a COMMERCIAL / MARKET grouping, not a legal or regulatory
 * classification. It does NOT map to any MINFOF tax category, felling class or
 * export schedule, and must never be presented to users as legal guidance.
 * Official categories change with policy and must be verified against current
 * MINFOF publications.
 */
enum TimberCategory: string
{
    case PrimaryHardwood = 'primary_hardwood';
    case PromotedSpecies = 'promoted_species';
    case SecondaryHardwood = 'secondary_hardwood';
    case Softwood = 'softwood';
    case Specialty = 'specialty';

    public function label(): string
    {
        return match ($this) {
            self::PrimaryHardwood => 'Primary / Principal Hardwood',
            self::PromotedSpecies => 'Promoted Species — Essence de Promotion',
            self::SecondaryHardwood => 'Secondary Hardwood',
            self::Softwood => 'Softwood',
            self::Specialty => 'Specialty / Precious Wood',
        };
    }

    /** Short label for compact UI (cards, badges). */
    public function shortLabel(): string
    {
        return match ($this) {
            self::PrimaryHardwood => 'Primary hardwood',
            self::PromotedSpecies => 'Promoted species',
            self::SecondaryHardwood => 'Secondary hardwood',
            self::Softwood => 'Softwood',
            self::Specialty => 'Specialty wood',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PrimaryHardwood => 'High-volume, well-established export hardwoods that make up the bulk of Cameroon timber trade.',
            self::PromotedSpecies => 'Lesser-known species actively promoted to broaden the harvest away from a handful of flagship timbers.',
            self::SecondaryHardwood => 'Hardwoods traded in smaller volumes, often for local processing or regional markets.',
            self::Softwood => 'Softwood and lightweight species, mainly used for plywood cores, packaging and light joinery.',
            self::Specialty => 'Rare, dense or highly figured woods sold in small volumes at premium prices.',
        };
    }

    /** Filament badge color key. */
    public function color(): string
    {
        return match ($this) {
            self::PrimaryHardwood => 'success',
            self::PromotedSpecies => 'info',
            self::SecondaryHardwood => 'gray',
            self::Softwood => 'warning',
            self::Specialty => 'danger',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
