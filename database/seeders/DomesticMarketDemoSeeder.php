<?php

namespace Database\Seeders;

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Enums\VerificationStage;
use App\Models\Capacity;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyGallery;
use App\Models\Product;
use App\Models\Species;
use App\Models\Verification;
use App\Models\VerificationBadge;
use Illuminate\Database\Seeder;

/**
 * Demo data for the five domestic-market/logistics public pages (gap-plan
 * 1.5): Buy Cameroon Wood, Transformation Network (+ Find a Transformer),
 * Made in Cameroon, Logistics Directory, and an artisan Portfolio page.
 *
 * Idempotent (firstOrCreate/updateOrCreate throughout) so it is safe to
 * re-run against the shared dev/testing database. Reuses the product photos
 * and supplier logos already shipped under public/img/ -- see ProductSeeder
 * and DemoCompanySeeder for the existing asset pool -- rather than
 * referencing image files that don't exist.
 */
class DomesticMarketDemoSeeder extends Seeder
{
    /** @var list<string> Real files under public/img/products/, reused cyclically for new demo products. */
    private const PRODUCT_IMAGES = [
        'products/ayous-plywood.jpg',
        'products/azobe-decking.jpg',
        'products/iroko-logs.jpg',
        'products/iroko-sawn-timber-38.jpg',
        'products/iroko-sawn-timber-75.jpg',
        'products/iroko-sawn-timber.jpg',
        'products/padouk-mouldings.jpg',
        'products/sapele-veneer.jpg',
        'products/tali-flooring.jpg',
    ];

    public function run(): void
    {
        $this->stockExistingManufacturers();
        $this->seedProcessors();
        $this->seedArtisans();
        $this->seedLogisticsCompanies();
    }

    /**
     * Five manufacturer companies already exist (from DemoCompanySeeder)
     * with zero products -- meaning the domestic-market pages only ever
     * showed listings from one company. Give each of them real,
     * Made-in-Cameroon-qualifying products.
     */
    private function stockExistingManufacturers(): void
    {
        $catalogue = [
            'sifor-timber' => [
                ['Moabi Sawn Timber KD', 'moabi', ProductType::SawnTimber, 410_000],
                ['Doussie Decking Boards', 'doussie', ProductType::Decking, 520_000],
            ],
            'cfc-wood-industry' => [
                ['Wenge Flooring Strips', 'wenge', ProductType::Flooring, 610_000],
                ['Sipo Structural Beams', 'sipo', ProductType::Beams, 380_000],
            ],
            'green-forest-industries' => [
                ['Okoume Plywood Sheets', 'okoume', ProductType::Plywood, 295_000],
                ['Ayous Mouldings', 'ayous', ProductType::Mouldings, 265_000],
            ],
            'natural-timber-solutions' => [
                ['Iroko Planks', 'iroko', ProductType::Planks, 445_000],
                ['Sapele Squares', 'sapele', ProductType::Squares, 470_000],
            ],
            'equator-timber' => [
                ['Azobe Utility Poles', 'azobe', ProductType::Poles, 350_000],
                ['Tali Sawn Slabs', 'tali', ProductType::Slabs, 505_000],
            ],
        ];

        $imageIndex = 0;

        foreach ($catalogue as $companySlug => $products) {
            $company = Company::where('slug', $companySlug)->first();

            if (! $company) {
                continue;
            }

            foreach ($products as [$name, $speciesSlug, $type, $price]) {
                $species = Species::where('slug', $speciesSlug)->first();

                $this->makeProduct(
                    ['company_id' => $company->id, 'name' => $name],
                    [
                        'species_id' => $species?->id,
                        'product_type' => $type,
                        'description' => "{$name}, supplied domestically by {$company->name} for Cameroonian workshops and joineries.",
                        'price_amount' => $price,
                        'price_currency' => 'XAF',
                        'price_unit' => PriceUnit::CubicMetre,
                        'moq_quantity' => 10,
                        'moq_unit' => PriceUnit::CubicMetre,
                        'grade' => 'Select & Better',
                        'thickness_mm' => 50,
                        'moisture_content' => '12% - 15% (KD)',
                        'origin' => 'Cameroon',
                        'status' => ProductStatus::Active,
                        'primary_image_path' => self::PRODUCT_IMAGES[$imageIndex % count(self::PRODUCT_IMAGES)],
                    ],
                );

                $imageIndex++;
            }
        }
    }

    /**
     * Three processor companies with real Capacity rows -- powers both the
     * Transformation Network capability filter and the "Find a Transformer"
     * species/quantity matching flow (Capacity::scopeMatching() +
     * Company::scopeHandlingSpecies()).
     */
    private function seedProcessors(): void
    {
        $processors = [
            [
                'legal_name' => 'Yaoundé Sawmill Cooperative Sarl',
                'trade_name' => 'Yaoundé Sawmill Co-op',
                'region' => 'Centre',
                'city' => 'Yaoundé',
                'species' => ['ayous', 'iroko'],
                'capacities' => [['Sawing', 500, 'month'], ['Kiln drying', 200, 'month']],
                'products' => [
                    ['Sawn Ayous Boards', 'ayous', ProductType::SawnTimber, 230_000],
                    ['Kiln-Dried Iroko Beams', 'iroko', ProductType::Beams, 415_000],
                ],
            ],
            [
                'legal_name' => 'Bafoussam Timber Processing Sarl',
                'trade_name' => 'Bafoussam Timber Processing',
                'region' => 'West',
                'city' => 'Bafoussam',
                'species' => ['sapele', 'padouk'],
                'capacities' => [['Planing', 150, 'month'], ['Moulding', 80, 'month']],
                'products' => [
                    ['Planed Sapele Boards', 'sapele', ProductType::Planks, 460_000],
                    ['Padouk Decorative Mouldings', 'padouk', ProductType::Mouldings, 290_000],
                ],
            ],
            [
                'legal_name' => 'Douala Wood Transformation Hub Sarl',
                'trade_name' => 'Douala Wood Transformation Hub',
                'region' => 'Littoral',
                'city' => 'Douala',
                'species' => ['azobe', 'tali'],
                'capacities' => [['CNC', 100, 'month'], ['Veneering', 60, 'month']],
                'products' => [
                    ['Azobe Deck Tiles', 'azobe', ProductType::Decking, 540_000],
                    ['Tali Veneer Sheets', 'tali', ProductType::Veneer, 380_000],
                ],
            ],
        ];

        foreach ($processors as $data) {
            $this->makeDomesticCompany(OrganisationType::Processor, $data);
        }
    }

    /**
     * Two artisan companies, each with products (so they show up on Made in
     * Cameroon) and portfolio gallery items (so /companies/{slug}/portfolio
     * has real content to demo).
     */
    private function seedArtisans(): void
    {
        $artisans = [
            [
                'legal_name' => 'Bamenda Furniture Workshop Sarl',
                'trade_name' => 'Bamenda Furniture Workshop',
                'region' => 'Northwest',
                'city' => 'Bamenda',
                'species' => ['iroko', 'acajou'],
                'capacities' => [['Furniture', 30, 'month'], ['Finishing', 30, 'month']],
                'products' => [
                    ['Iroko Dining Table Set', 'iroko', ProductType::Planks, 620_000],
                ],
                'portfolio' => [
                    ['Custom Iroko Dining Table', 'Hand-finished dining set for a Bamenda hospitality client.', 'Iroko'],
                    ['Carved Cabinet Doors', 'Bespoke kitchen cabinetry with traditional carved panelling.', 'Acajou'],
                ],
            ],
            [
                'legal_name' => 'Douala Artisan Crafts Sarl',
                'trade_name' => 'Douala Artisan Crafts',
                'region' => 'Littoral',
                'city' => 'Douala',
                'species' => ['ayous', 'wenge'],
                'capacities' => [['Joinery', 20, 'month']],
                'products' => [
                    ['Ayous Decorative Panels', 'ayous', ProductType::LaminatedPanels, 210_000],
                ],
                'portfolio' => [
                    ['Wenge Feature Wall Panelling', 'Interior fit-out for a Douala office lobby.', 'Wenge'],
                    ['Ayous Room Divider Screens', 'Made-to-order laminated screen panels.', 'Ayous'],
                ],
            ],
        ];

        foreach ($artisans as $data) {
            $company = $this->makeDomesticCompany(OrganisationType::Artisan, $data);

            foreach ($data['portfolio'] ?? [] as $i => [$caption, $description, $material]) {
                CompanyGallery::firstOrCreate(
                    ['company_id' => $company->id, 'caption' => $caption],
                    [
                        'image_path' => self::PRODUCT_IMAGES[$i % count(self::PRODUCT_IMAGES)],
                        'description' => $description,
                        'materials_used' => $material,
                        'completed_on' => now()->subMonths($i + 1)->toDateString(),
                        'is_portfolio' => true,
                        'sort_order' => $i,
                    ],
                );
            }
        }
    }

    /**
     * Two additional logistics companies (a third already exists from
     * DemoCompanySeeder with neither a website nor a Verification row --
     * i.e. it already demos the "Unverified" tier) covering the other two
     * LogisticsDirectoryController tiers: Trusted (verified, no website)
     * and Tech-enabled (verified + website_url).
     */
    private function seedLogisticsCompanies(): void
    {
        $trusted = Company::firstOrCreate(
            ['slug' => 'highland-transport-services'],
            [
                'legal_name' => 'Highland Transport Services Sarl',
                'trade_name' => 'Highland Transport Services',
                'type' => OrganisationType::Logistics,
                'status' => CompanyStatus::Verified,
                'description' => 'Regional haulage and warehousing for timber and wood products across the North West.',
                'region' => 'Northwest',
                'city' => 'Bamenda',
                'country_code' => 'CM',
                'verified_at' => now(),
                'logo_path' => 'companies/demo/logo.png',
            ],
        );
        $this->ensurePubliclyVisible($trusted, ['ayous']);
        $this->ensureVerification($trusted);

        $techEnabled = Company::firstOrCreate(
            ['slug' => 'cameroon-freight-logistics'],
            [
                'legal_name' => 'Cameroon Freight Logistics Sarl',
                'trade_name' => 'Cameroon Freight Logistics',
                'type' => OrganisationType::Logistics,
                'status' => CompanyStatus::Verified,
                'description' => 'Port-to-door freight, container trucking and tracked delivery for the Douala–Yaoundé corridor.',
                'region' => 'Littoral',
                'city' => 'Douala',
                'country_code' => 'CM',
                'verified_at' => now(),
                'logo_path' => 'companies/demo/logo.png',
                'website_url' => 'https://cameroonfreightlogistics.example',
            ],
        );
        $this->ensurePubliclyVisible($techEnabled, ['iroko']);
        $this->ensureVerification($techEnabled);
    }

    /**
     * Shared builder for a publicly-visible domestic-market company plus
     * its named species, Capacity rows and products.
     *
     * @param  array{legal_name: string, trade_name: string, region: string, city: string, species: list<string>, capacities: list<array{0: string, 1: float, 2: string}>, products: list<array{0: string, 1: string, 2: ProductType, 3: int}>}  $data
     */
    private function makeDomesticCompany(OrganisationType $type, array $data): Company
    {
        $slug = \Illuminate\Support\Str::slug($data['trade_name']);

        $company = Company::firstOrCreate(
            ['slug' => $slug],
            [
                'legal_name' => $data['legal_name'],
                'trade_name' => $data['trade_name'],
                'type' => $type,
                'status' => CompanyStatus::Verified,
                'description' => "{$data['trade_name']} is a verified Cameroon-based {$type->label()} serving the domestic timber market.",
                'region' => $data['region'],
                'city' => $data['city'],
                'country_code' => 'CM',
                'verified_at' => now(),
                'logo_path' => 'companies/demo/logo.png',
            ],
        );

        $this->ensurePubliclyVisible($company, $data['species']);
        $this->ensureVerification($company);

        foreach ($data['capacities'] as [$capability, $quantity, $period]) {
            Capacity::firstOrCreate([
                'owner_type' => Company::class,
                'owner_id' => $company->id,
                'capability' => $capability,
            ], [
                'quantity' => $quantity,
                'unit' => 'm3',
                'period' => $period,
            ]);
        }

        foreach ($data['products'] as $i => [$name, $speciesSlug, $productType, $price]) {
            $species = Species::where('slug', $speciesSlug)->first();

            $this->makeProduct(
                ['company_id' => $company->id, 'name' => $name],
                [
                    'species_id' => $species?->id,
                    'product_type' => $productType,
                    'description' => "{$name}, produced by {$data['trade_name']} for the Cameroonian domestic market.",
                    'price_amount' => $price,
                    'price_currency' => 'XAF',
                    'price_unit' => PriceUnit::CubicMetre,
                    'moq_quantity' => 5,
                    'moq_unit' => PriceUnit::CubicMetre,
                    'grade' => 'Select & Better',
                    'origin' => 'Cameroon',
                    'status' => ProductStatus::Active,
                    'primary_image_path' => self::PRODUCT_IMAGES[$i % count(self::PRODUCT_IMAGES)],
                ],
            );
        }

        return $company;
    }

    /**
     * Create-or-update a demo product. Seeders run under WithoutModelEvents,
     * so Product::booted()'s slug + public_id hooks do not fire — both
     * generated columns are assigned explicitly on first insert and never
     * reassigned on re-run.
     */
    private function makeProduct(array $match, array $attributes): Product
    {
        $product = Product::firstOrNew($match);
        $product->fill($attributes);

        if (blank($product->slug)) {
            $product->slug = \Illuminate\Support\Str::slug($match['name'].'-'.$match['company_id']);
        }

        if (blank($product->public_id)) {
            $product->public_id = \App\Support\ProductIdentifier::forProduct($product);
        }

        $product->save();

        return $product;
    }

    /** Satisfies every Company::scopePubliclyVisible() predicate, with real named species attached. */
    private function ensurePubliclyVisible(Company $company, array $speciesSlugs): void
    {
        $company->contacts()->firstOrCreate(
            ['company_id' => $company->id, 'is_public' => true],
            ['name' => 'Sales Desk', 'title' => 'Sales', 'email' => $company->email, 'phone' => $company->phone],
        );

        $speciesIds = Species::whereIn('slug', $speciesSlugs)->pluck('id');
        if ($speciesIds->isNotEmpty()) {
            $company->species()->syncWithoutDetaching($speciesIds);
        }

        VerificationBadge::firstOrCreate(
            ['company_id' => $company->id],
            [
                'badge_type' => \App\Enums\BadgeType::VerifiedExporter,
                'status' => \App\Enums\BadgeStatus::Active,
                'issued_at' => now(),
                'valid_until' => now()->addYear()->toDateString(),
                'is_public' => true,
                'reference_code' => 'CTH-DOM-'.str_pad((string) $company->id, 4, '0', STR_PAD_LEFT),
            ],
        );
    }

    /** Gives the company an active Verification (stage=Verified) so Company::isVerified() is true. */
    private function ensureVerification(Company $company): void
    {
        if ($company->isVerified()) {
            return;
        }

        Verification::firstOrCreate(
            ['entity_type' => Company::class, 'entity_id' => $company->id],
            ['stage' => VerificationStage::Verified],
        );
    }
}
