<?php

namespace App\Enums;

/**
 * The brief's target Organisation.type taxonomy (CTH_Claude_Code_Build_Brief.md
 * §10) — 10 cases, superseding the 5-value App\Enums\SupplierType long-term.
 *
 * Deliberately does NOT replace SupplierType yet. See the "Scope decision"
 * section of docs/superpowers/plans/2026-08-27-organisation-type-migration.md:
 * two of SupplierType's five values (exporter, trader) have no clean
 * equivalent here and are BLOCKED pending a human decision. SupplierType and
 * companies.supplier_type stay in place and in use until that decision is
 * made and the 12 consuming files are migrated in a dedicated follow-up
 * (tracked in docs/GAP_PLAN.md as the successor to item 0.6).
 */
enum OrganisationType: string
{
    case Supplier = 'supplier';
    case Processor = 'processor';
    case Manufacturer = 'manufacturer';
    case Artisan = 'artisan';
    case Buyer = 'buyer';
    case Retailer = 'retailer';
    case Logistics = 'logistics';
    case CarbonDeveloper = 'carbon_developer';
    case Financier = 'financier';
    case TrainingProvider = 'training_provider';

    public function label(): string
    {
        return match ($this) {
            self::Supplier => 'Supplier',
            self::Processor => 'Processor',
            self::Manufacturer => 'Manufacturer',
            self::Artisan => 'Artisan',
            self::Buyer => 'Buyer',
            self::Retailer => 'Retailer',
            self::Logistics => 'Logistics',
            self::CarbonDeveloper => 'Carbon Project Developer',
            self::Financier => 'Financier',
            self::TrainingProvider => 'Training Provider',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Supplier => 'success',
            self::Processor => 'info',
            self::Manufacturer => 'success',
            self::Artisan => 'warning',
            self::Buyer => 'primary',
            self::Retailer => 'warning',
            self::Logistics => 'danger',
            self::CarbonDeveloper => 'success',
            self::Financier => 'info',
            self::TrainingProvider => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
