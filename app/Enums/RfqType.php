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
    case Transport = 'transport';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Export',
            self::DomesticManufacturing => 'Manufacturing / Local Procurement',
            self::Transport => 'Transport',
        };
    }

    /**
     * The organisation types this kind of RFQ should be offered to, or null
     * for "no type restriction" (Export keeps its original species-led
     * candidate set). Drives RfqMatchingService candidates and the admin
     * routing selector's "suggested" group.
     *
     * @return list<OrganisationType>|null
     */
    public function targetOrganisationTypes(): ?array
    {
        return match ($this) {
            self::Export => null,
            self::DomesticManufacturing => [
                OrganisationType::Manufacturer,
                OrganisationType::Artisan,
                OrganisationType::Processor,
            ],
            self::Transport => [OrganisationType::Logistics],
        };
    }

    /** Whether candidates must handle the RFQ's species (not for transport). */
    public function requiresSpeciesMatch(): bool
    {
        return $this !== self::Transport;
    }
}
