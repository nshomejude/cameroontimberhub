<?php

namespace App\Console\Commands;

use App\Support\Geo\SeedCompanyCoordinates;
use Illuminate\Console\Command;

/**
 * Fills latitude/longitude (and a missing address line) for the seeded
 * demo/directory companies, by slug, from App\Support\Geo\SeedCompanyCoordinates.
 * Idempotent: without --force only NULL coordinates are written.
 */
class BackfillCompanyCoordinates extends Command
{
    protected $signature = 'companies:backfill-coordinates {--force : Overwrite existing coordinates for the seeded slugs}';

    protected $description = 'Backfill Cameroon coordinates for seeded demo/directory companies (by slug).';

    public function handle(): int
    {
        $updated = SeedCompanyCoordinates::apply((bool) $this->option('force'));

        $this->info("Updated {$updated} compan".($updated === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
