<?php

namespace App\Enums;

/**
 * What kind of request an RFQ is. `Export` is the original, single existing
 * behavior — every RFQ before this enum existed backfills to it. This is
 * additive: it does not change how the export wizard behaves.
 */
enum RfqType: string
{
    case Export = 'export';
    case DomesticManufacturing = 'domestic_manufacturing';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Export',
            self::DomesticManufacturing => 'Manufacturing / Local Procurement',
        };
    }
}
