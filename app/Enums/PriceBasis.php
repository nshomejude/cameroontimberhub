<?php

namespace App\Enums;

/**
 * Pricing basis for a PriceObservation (docs/PRICE_DATA_STANDARD.md §1).
 *
 * Broader than an Incoterm: `ex_mill` and `delivered` are domestic Cameroon
 * pricing conventions, not Incoterms 2020 terms. The first five cases alias
 * 1:1 onto App\Enums\RfqIncoterm so an observation built from an export order
 * can carry the wider basis without translation loss.
 */
enum PriceBasis: string
{
    case Fob = 'fob';
    case Cif = 'cif';
    case Cfr = 'cfr';
    case Exw = 'exw';
    case Dap = 'dap';
    case ExMill = 'ex_mill';
    case Delivered = 'delivered';
    case Other = 'other';

    /** Map an export-leg Incoterm onto the wider basis vocabulary. */
    public static function fromIncoterm(RfqIncoterm|string|null $incoterm): ?self
    {
        if ($incoterm === null) {
            return null;
        }

        $value = $incoterm instanceof RfqIncoterm ? $incoterm->value : $incoterm;

        return match (strtoupper($value)) {
            'FOB' => self::Fob,
            'CIF' => self::Cif,
            'CFR' => self::Cfr,
            'EXW' => self::Exw,
            'DAP' => self::Dap,
            'OTHER' => self::Other,
            default => null,
        };
    }

    public function label(): string
    {
        return __('messages.enums.price_basis.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
