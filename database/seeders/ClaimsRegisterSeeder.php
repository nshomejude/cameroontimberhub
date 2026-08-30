<?php

namespace Database\Seeders;

use App\Models\Claim;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Registers the real public claims already live on the platform
 * (implementation blueprint §48). Idempotent via firstOrCreate keyed on
 * claim_text — safe to re-run.
 *
 * Sources verified against app/Http/Controllers/Public/HomeController.php's
 * stats() method (homepage hero/stats band) and the "Supplier-reported
 * price" labelling added in an earlier session's product-price audit.
 */
class ClaimsRegisterSeeder extends Seeder
{
    public function run(): void
    {
        $reviewDate = Carbon::today()->addMonths(6);

        $claims = [
            [
                'claim_text' => 'Homepage stats band: "[N] Verified Suppliers"',
                'page_location' => 'Homepage hero stats',
                'evidence_source' => 'Live count from Company::publiclyVisible()->count() (app/Http/Controllers/Public/HomeController.php stats()).',
            ],
            [
                'claim_text' => 'Homepage stats band: "[N] Timber Products"',
                'page_location' => 'Homepage hero stats',
                'evidence_source' => 'Live count from Product::active()->whereHas(\'company\', fn ($c) => $c->publiclyVisible())->count() (HomeController stats()).',
            ],
            [
                'claim_text' => 'Homepage stats band: "[N] RFQs Completed"',
                'page_location' => 'Homepage hero stats',
                'evidence_source' => 'Live count from Rfq::query()->count() (HomeController stats()).',
            ],
            [
                'claim_text' => 'Homepage stats band: "[N] Countries Served"',
                'page_location' => 'Homepage hero stats',
                'evidence_source' => 'Live distinct count from CompanyExportMarket::query()->whereHas(\'company\', fn ($c) => $c->publiclyVisible())->distinct()->count(\'country_code\') (HomeController stats()).',
            ],
            [
                'claim_text' => '"Supplier-reported price" label shown next to product prices',
                'page_location' => 'Product listing / detail pages',
                'evidence_source' => 'Labeled per-price, sourced directly from Product.price_amount, no independent verification.',
            ],
        ];

        foreach ($claims as $claim) {
            Claim::firstOrCreate(
                ['claim_text' => $claim['claim_text']],
                $claim + [
                    'status' => 'approved',
                    'approved_at' => Carbon::today(),
                    'review_date' => $reviewDate,
                ]
            );
        }
    }
}
