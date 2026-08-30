<?php

namespace Database\Seeders;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Enums\VerificationStage;
use App\Models\CarbonProject;
use App\Models\Capacity;
use App\Models\Company;
use App\Models\Species;
use App\Models\Verification;
use App\Models\VerificationBadge;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data for the two newest public pages that shipped functionally
 * empty: /carbon-projects (App\Models\CarbonProject rows for
 * OrganisationType::CarbonDeveloper companies) and the capacity chips on
 * /logistics-directory (App\Models\Capacity rows for
 * OrganisationType::Logistics companies).
 *
 * Idempotent (firstOrCreate/updateOrCreate throughout), mirroring
 * DomesticMarketDemoSeeder's conventions -- safe to re-run.
 */
class DomesticServiceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->stockLogisticsCapacities();
        $this->seedCarbonDevelopers();
    }

    /**
     * Every existing Logistics-type company gets 1-2 realistic Capacity
     * rows so the /logistics-directory capacity chips have real content.
     */
    private function stockLogisticsCapacities(): void
    {
        $capacitiesByCompany = [
            'atlas-wood-exports' => [
                ['Trucking', 500, 'm3', 'month'],
            ],
            'highland-transport-services' => [
                ['Trucking', 350, 'm3', 'month'],
                ['Warehousing', 1500, 'm2', 'month'],
            ],
            'cameroon-freight-logistics' => [
                ['Trucking', 800, 'm3', 'month'],
                ['Warehousing', 2000, 'm2', 'month'],
            ],
        ];

        foreach ($capacitiesByCompany as $slug => $capacities) {
            $company = Company::where('slug', $slug)
                ->where('type', OrganisationType::Logistics->value)
                ->first();

            if (! $company) {
                continue;
            }

            foreach ($capacities as [$capability, $quantity, $unit, $period]) {
                Capacity::firstOrCreate([
                    'owner_type' => Company::class,
                    'owner_id' => $company->id,
                    'capability' => $capability,
                ], [
                    'quantity' => $quantity,
                    'unit' => $unit,
                    'period' => $period,
                ]);
            }
        }
    }

    /**
     * Three new carbon-developer companies with CarbonProject rows so
     * /carbon-projects has real, publicly-visible content.
     */
    private function seedCarbonDevelopers(): void
    {
        $developers = [
            [
                'legal_name' => 'Adamawa Reforestation Initiative Sarl',
                'trade_name' => 'Adamawa Reforestation Initiative',
                'region' => 'Adamawa',
                'city' => 'Ngaoundéré',
                'species' => ['ayous'],
                'projects' => [
                    [
                        'name' => 'Ngaoundéré Highlands Reforestation Project',
                        'project_type' => 'reforestation',
                        'region' => 'Adamawa',
                        'area_hectares' => 1200,
                        'estimated_credits_per_year' => 8500,
                        'description' => 'Community-led reforestation of degraded savanna land around Ngaoundéré, restoring native tree cover and local livelihoods.',
                    ],
                    [
                        'name' => 'Meiganga Agroforestry Belt',
                        'project_type' => 'agroforestry',
                        'region' => 'Adamawa',
                        'area_hectares' => 600,
                        'estimated_credits_per_year' => 3200,
                        'description' => 'Mixed tree-and-crop agroforestry corridors that stabilise soils and diversify farmer income near Meiganga.',
                    ],
                ],
            ],
            [
                'legal_name' => 'East Cameroon Forest Carbon Sarl',
                'trade_name' => 'East Cameroon Forest Carbon',
                'region' => 'East',
                'city' => 'Bertoua',
                'species' => ['iroko'],
                'projects' => [
                    [
                        'name' => 'Bertoua Avoided Deforestation Project',
                        'project_type' => 'avoided_deforestation',
                        'region' => 'East',
                        'area_hectares' => 4500,
                        'estimated_credits_per_year' => 21000,
                        'description' => 'Protects standing natural forest under active logging pressure near Bertoua through community forest-management agreements.',
                    ],
                    [
                        'name' => 'Yokadouma Afforestation Programme',
                        'project_type' => 'afforestation',
                        'region' => 'East',
                        'area_hectares' => 900,
                        'estimated_credits_per_year' => 5400,
                        'description' => 'Establishes new forest cover on previously unforested land around Yokadouma to sequester carbon and buffer wildlife corridors.',
                    ],
                ],
            ],
            [
                'legal_name' => 'South Region Carbon Forestry Sarl',
                'trade_name' => 'South Region Carbon Forestry',
                'region' => 'South',
                'city' => 'Ebolowa',
                'species' => ['moabi'],
                'projects' => [
                    [
                        'name' => 'Ebolowa Community Reforestation Project',
                        'project_type' => 'reforestation',
                        'region' => 'South',
                        'area_hectares' => 800,
                        'estimated_credits_per_year' => 4700,
                        'description' => 'Replants native hardwood species on degraded plantation land around Ebolowa in partnership with local cooperatives.',
                    ],
                ],
            ],
        ];

        foreach ($developers as $data) {
            $company = $this->makeCarbonDeveloper($data);

            foreach ($data['projects'] as $project) {
                CarbonProject::firstOrCreate(
                    ['company_id' => $company->id, 'name' => $project['name']],
                    [
                        'project_type' => $project['project_type'],
                        'region' => $project['region'],
                        'area_hectares' => $project['area_hectares'],
                        'estimated_credits_per_year' => $project['estimated_credits_per_year'],
                        'description' => $project['description'],
                        'status' => ProductStatus::Active->value,
                    ],
                );
            }
        }
    }

    /**
     * @param  array{legal_name: string, trade_name: string, region: string, city: string, species: list<string>}  $data
     */
    private function makeCarbonDeveloper(array $data): Company
    {
        $slug = Str::slug($data['trade_name']);

        $company = Company::firstOrCreate(
            ['slug' => $slug],
            [
                'legal_name' => $data['legal_name'],
                'trade_name' => $data['trade_name'],
                'type' => OrganisationType::CarbonDeveloper,
                'status' => CompanyStatus::Verified,
                'description' => "{$data['trade_name']} develops and manages verified carbon and reforestation projects across Cameroon.",
                'region' => $data['region'],
                'city' => $data['city'],
                'country_code' => 'CM',
                'verified_at' => now(),
                'logo_path' => 'companies/demo/logo.png',
            ],
        );

        $company->contacts()->firstOrCreate(
            ['company_id' => $company->id, 'is_public' => true],
            ['name' => 'Projects Desk', 'title' => 'Projects', 'email' => $company->email, 'phone' => $company->phone],
        );

        $speciesIds = Species::whereIn('slug', $data['species'])->pluck('id');
        if ($speciesIds->isNotEmpty()) {
            $company->species()->syncWithoutDetaching($speciesIds);
        }

        VerificationBadge::firstOrCreate(
            ['company_id' => $company->id],
            [
                'badge_type' => BadgeType::VerifiedExporter,
                'status' => BadgeStatus::Active,
                'issued_at' => now(),
                'valid_until' => now()->addYear()->toDateString(),
                'is_public' => true,
                'reference_code' => 'CTH-CRB-'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT),
            ],
        );

        if (! $company->isVerified()) {
            Verification::firstOrCreate(
                ['entity_type' => Company::class, 'entity_id' => $company->id],
                ['stage' => VerificationStage::Verified],
            );
        }

        return $company;
    }
}
