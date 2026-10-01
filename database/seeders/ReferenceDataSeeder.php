<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data the platform needs to function — safe (and required) in
 * production. Every seeder here is idempotent (firstOrCreate/updateOrCreate),
 * so it can be re-run after a deploy:
 *
 *   php artisan db:seed --class=ReferenceDataSeeder --force
 *
 * Creates NO users and NO companies.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            PlanSeeder::class,
            TaxRuleSeeder::class,
            CommissionRuleSeeder::class, // after PlanSeeder: only seeds tiers whose plan exists
            DocumentTypeSeeder::class,
            SpeciesSeeder::class,
            PageSeeder::class,
            GlossaryTermSeeder::class,
        ]);
    }
}
