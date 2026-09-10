<?php

namespace Database\Seeders;

use App\Models\TaxRule;
use Illuminate\Database\Seeder;

/**
 * Seeds the single Cameroon TVA rule (billing engine M5, plan §7.3).
 *
 * It ships INACTIVE. Finance/legal flip `is_active` on (in /admin → Tax
 * Rules, no deploy) once CTH's DGI TVA registration is confirmed. Customers
 * outside Cameroon are zero-rated — that is simply the absence of any rule,
 * so nothing is seeded for the rest of the world.
 */
class TaxRuleSeeder extends Seeder
{
    public function run(): void
    {
        TaxRule::updateOrCreate(
            ['jurisdiction' => 'CM', 'name' => 'Cameroon TVA'],
            [
                'rate' => 0.1925,
                'applies_to' => null,
                'is_active' => false,
                'effective_from' => null,
                'effective_until' => null,
                'notes' => 'Cameroon VAT (TVA) at 19.25% on domestic subscription and service fees. '
                    .'Stays INACTIVE until CTH\'s DGI TVA registration and filing obligation are confirmed by finance/legal '
                    .'(billing plan §7.3); activate here with no deploy once confirmed. Non-Cameroon customers are zero-rated.',
            ],
        );
    }
}
