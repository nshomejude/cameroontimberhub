<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\SupplierTypeMigrationMap;
use Illuminate\Console\Command;

/**
 * Backfills companies.type from companies.supplier_type for the mappings in
 * SupplierTypeMigrationMap::MAP only. Idempotent and safe to re-run: it only
 * ever writes to rows where `type IS NULL`, so it never overwrites a value
 * set by hand (e.g. via the admin form once the OrganisationType Select
 * ships) or by a previous run.
 *
 * Rows whose supplier_type has no entry in the map (today: exporter, trader,
 * service_provider, and any NULL supplier_type) are left with type = NULL
 * and are not reported as errors — see companies:organisation-type-gaps for
 * a report of exactly which rows those are.
 */
class BackfillOrganisationType extends Command
{
    protected $signature = 'companies:backfill-organisation-type';

    protected $description = 'Backfill companies.type from companies.supplier_type for values with an unambiguous OrganisationType equivalent.';

    public function handle(): int
    {
        $total = 0;

        foreach (SupplierTypeMigrationMap::MAP as $oldValue => $newValue) {
            $updated = Company::query()
                ->where('supplier_type', $oldValue)
                ->whereNull('type')
                ->update(['type' => $newValue]);

            $this->info("Backfilled {$updated} compan".($updated === 1 ? 'y' : 'ies')." with supplier_type={$oldValue} to type={$newValue}.");

            $total += $updated;
        }

        $this->info("Done. {$total} companies backfilled.");

        return self::SUCCESS;
    }
}
