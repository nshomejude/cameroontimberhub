<?php

namespace Database\Seeders;

use App\Support\Geo\SeedCompanyCoordinates;
use Illuminate\Database\Seeder;

/**
 * Gives every seeded demo/directory company realistic Cameroon coordinates
 * (and an address line when it has none), by slug. Idempotent: only fills
 * NULL values, never overwrites coordinates set by a company or an admin.
 * Companies not yet created are simply skipped. Production runs the same
 * logic via `php artisan companies:backfill-coordinates`.
 */
class CompanyCoordinatesSeeder extends Seeder
{
    public function run(): void
    {
        SeedCompanyCoordinates::apply();
    }
}
