<?php

namespace App\Enums;

/**
 * Coarse quantity bucket for a PriceObservation (docs/PRICE_DATA_STANDARD.md §1).
 *
 * A small, fixed set of bands so the N>=5 / M>=10 aggregation thresholds
 * (standard §4) are checked per band rather than per exact quantity. Bands are
 * m3-equivalent; classification is by raw numeric quantity — no unit
 * conversion is attempted here.
 */
enum PriceVolumeBand: string
{
    case Sample = 'sample';   // < 1
    case Small = 'small';     // 1 – 10
    case Medium = 'medium';   // 10 – 50
    case Large = 'large';     // 50 – 200
    case Bulk = 'bulk';       // 200 +

    /** Classify a raw quantity into its band. Lower bound inclusive. */
    public static function forQuantity(float $quantity): self
    {
        return match (true) {
            $quantity < 1.0 => self::Sample,
            $quantity < 10.0 => self::Small,
            $quantity < 50.0 => self::Medium,
            $quantity < 200.0 => self::Large,
            default => self::Bulk,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
