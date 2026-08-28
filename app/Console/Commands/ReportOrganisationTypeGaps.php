<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\SupplierTypeMigrationMap;
use Illuminate\Console\Command;

/**
 * Makes the exporter/trader (and any other unmapped) gap visible and
 * countable instead of a silent NULL. Run this after
 * companies:backfill-organisation-type to see exactly which companies still
 * need a type — i.e. every row whose supplier_type is set but has no entry
 * in SupplierTypeMigrationMap::MAP, or whose supplier_type is set but type
 * is still NULL for any other reason.
 */
class ReportOrganisationTypeGaps extends Command
{
    protected $signature = 'companies:organisation-type-gaps';

    protected $description = 'List companies with a supplier_type but no backfilled companies.type (exporter, trader, and any other unmapped value).';

    public function handle(): int
    {
        $gaps = Company::query()
            ->whereNotNull('supplier_type')
            ->whereNull('type')
            ->get(['id', 'legal_name', 'supplier_type']);

        if ($gaps->isEmpty()) {
            $this->info('0 companies with an unmapped supplier_type.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'supplier_type', 'Mapped in SupplierTypeMigrationMap?'],
            $gaps->map(fn (Company $company) => [
                $company->id,
                $company->legal_name,
                $company->supplier_type?->value,
                array_key_exists($company->supplier_type?->value, SupplierTypeMigrationMap::MAP) ? 'yes' : 'no',
            ]),
        );

        $this->warn("{$gaps->count()} companies have a supplier_type with no companies.type mapping yet.");

        return self::SUCCESS;
    }
}
