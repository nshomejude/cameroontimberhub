<?php

namespace Database\Seeders;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
use App\Enums\SupplierType;
use App\Models\Company;
use App\Models\Species;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoCompanySeeder extends Seeder
{
    public function run(): void
    {
        $exporter = User::firstOrCreate(
            ['email' => 'exporter@cameroontimberhub.test'],
            ['name' => 'Demo Exporter', 'password' => Hash::make('password')],
        );

        $kuete = $this->makeVisibleCompany([
            'legal_name' => 'Kuété Timber Group Sarl',
            'trade_name' => 'Kuété Timber',
            'description' => 'Kuété Timber Group is a Douala-based exporter of Cameroonian hardwoods, supplying logs and sawn timber to buyers across Europe and Asia.',
            'region' => 'Littoral',
            'city' => 'Douala',
            'email' => 'sales@kuete.example',
            'phone' => '+237 6 99 00 00 00',
            'supplier_type' => SupplierType::Exporter,
            'response_rate_percent' => 94,
            'years_experience' => 18,
        ], ['sapele', 'iroko', 'ayous'], ['FR', 'NL', 'CN', 'US'], 'CTH-DEMO-0001');

        $exporter->companies()->syncWithoutDetaching([
            $kuete->id => ['role' => 'owner', 'is_primary' => true],
        ]);

        $this->makeVisibleCompany([
            'legal_name' => 'Sangha Forest Products Sarl',
            'trade_name' => 'Sangha Forest',
            'description' => 'Sangha Forest Products operates in the East region, exporting Tali, Padouk and Azobé for heavy construction and decking markets worldwide.',
            'region' => 'East',
            'city' => 'Bertoua',
            'email' => 'contact@sangha.example',
            'phone' => '+237 6 70 00 00 00',
            'supplier_type' => SupplierType::Manufacturer,
            'response_rate_percent' => 88,
            'years_experience' => 12,
        ], ['tali', 'padouk', 'azobe'], ['BE', 'GB', 'CN'], 'CTH-DEMO-0002');

        // Featured verified suppliers shown on the landing page "Verified Timber
        // Suppliers" row (four cards, per the approved mockup).
        $this->makeVisibleCompany([
            'legal_name' => 'Pallisco Cameroon Sarl',
            'trade_name' => 'Pallisco Cameroon',
            'description' => 'Pallisco Cameroon exports FSC-controlled sawn timber and logs from Douala to European joinery and construction markets.',
            'region' => 'Littoral',
            'city' => 'Douala',
            'email' => 'export@pallisco.example',
            'phone' => '+237 6 55 00 00 00',
            'year_founded' => 2009,
            'is_featured' => true,
            'supplier_type' => SupplierType::Exporter,
            'response_rate_percent' => 97,
            'years_experience' => 16,
        ], ['sapele', 'ayous', 'iroko'], ['FR', 'BE', 'IT', 'ES', 'DE', 'NL', 'PT', 'GB'], 'CTH-DEMO-0003');

        $this->makeVisibleCompany([
            'legal_name' => 'SIFOR Timber Sarl',
            'trade_name' => 'SIFOR Timber',
            'description' => 'SIFOR Timber mills and ships hardwood decking, flooring and mouldings from Kribi deep-sea port to buyers on four continents.',
            'region' => 'South',
            'city' => 'Kribi',
            'email' => 'trade@sifor.example',
            'phone' => '+237 6 77 00 00 00',
            'year_founded' => 1999,
            'is_featured' => true,
            'supplier_type' => SupplierType::Manufacturer,
            'response_rate_percent' => 91,
            'years_experience' => 26,
        ], ['tali', 'azobe', 'padouk'], ['CN', 'VN', 'IN', 'US', 'TR', 'GR', 'MA', 'AE', 'JP', 'KR'], 'CTH-DEMO-0004');

        $this->makeVisibleCompany([
            'legal_name' => 'CFC Wood Industry Sarl',
            'trade_name' => 'CFC Wood Industry',
            'description' => 'CFC Wood Industry is a Bafoussam-based processor supplying veneer, plywood and finished timber components to international buyers.',
            'region' => 'West',
            'city' => 'Bafoussam',
            'email' => 'sales@cfcwood.example',
            'phone' => '+237 6 91 00 00 00',
            'year_founded' => 2006,
            'is_featured' => true,
            'supplier_type' => SupplierType::Manufacturer,
            'response_rate_percent' => 89,
            'years_experience' => 19,
        ], ['ayous', 'sapele', 'movingui'], ['CN', 'US', 'GB', 'FR', 'ZA', 'EG'], 'CTH-DEMO-0005');

        // Supplier-directory demo set: the eight suppliers drawn in the approved
        // /companies mockup, with the logo badges extracted from it.
        foreach ($this->directorySuppliers() as $i => $supplier) {
            [$attrs, $species, $markets] = $supplier;

            $this->makeVisibleCompany($attrs, $species, $markets, sprintf('CTH-DEMO-%04d', 6 + $i));
        }

        // A draft company — must never surface publicly (visibility-gate demo).
        Company::firstOrCreate(['slug' => 'pending-mill'], [
            'legal_name' => 'Pending Mill Sarl',
            'status' => CompanyStatus::Draft,
            'description' => 'A company still completing onboarding; not yet verified.',
            'region' => 'Centre',
            'city' => 'Yaoundé',
        ]);
    }

    /**
     * The eight cards drawn on the approved supplier-directory mockup.
     *
     * @return list<array{0: array<string, mixed>, 1: list<string>, 2: list<string>}>
     */
    private function directorySuppliers(): array
    {
        return [
            [[
                'slug' => 'african-wood-exporters',
                'legal_name' => 'African Wood Exporters SARL',
                'trade_name' => 'African Wood Exporters SARL',
                'logo_path' => 'suppliers/logos/african-wood-exporters.png',
                'description' => 'African Wood Exporters ships hardwood logs, plywood and sawn timber from Douala to joinery and construction buyers across Europe and Asia.',
                'region' => 'Littoral', 'city' => 'Douala',
                'email' => 'sales@africanwood.example', 'phone' => '+237 6 50 00 00 01',
                'supplier_type' => SupplierType::Exporter,
                'response_rate_percent' => 98, 'years_experience' => 10, 'is_featured' => true,
            ], ['iroko', 'ayous', 'sapele'], ['FR', 'NL', 'DE', 'CN', 'IN']],

            [[
                'slug' => 'bois-et-nature-cameroon',
                'legal_name' => 'Bois & Nature Cameroon Sarl',
                'trade_name' => 'Bois & Nature Cameroon',
                'logo_path' => 'suppliers/logos/bois-et-nature-cameroon.png',
                'description' => 'Bois & Nature Cameroon supplies sawn timber, veneer, logs and mouldings from its Yaoundé yard to buyers in Europe and the Middle East.',
                'region' => 'Centre', 'city' => 'Yaoundé',
                'email' => 'contact@boisnature.example', 'phone' => '+237 6 50 00 00 02',
                'supplier_type' => SupplierType::Exporter,
                'response_rate_percent' => 96, 'years_experience' => 8,
            ], ['ayous', 'sapele', 'bosse'], ['FR', 'ES', 'AE', 'TR']],

            [[
                'slug' => 'green-forest-industries',
                'legal_name' => 'Green Forest Industries Sarl',
                'trade_name' => 'Green Forest Industries',
                'logo_path' => 'suppliers/logos/green-forest-industries.png',
                'description' => 'Green Forest Industries mills plywood, kiln-dried stock and mouldings in Ebolowa for export to European and North American buyers.',
                'region' => 'South', 'city' => 'Ebolowa',
                'email' => 'export@greenforest.example', 'phone' => '+237 6 50 00 00 03',
                'supplier_type' => SupplierType::Manufacturer,
                'response_rate_percent' => 94, 'years_experience' => 6,
            ], ['ayous', 'iroko', 'okoume'], ['US', 'CA', 'GB', 'BE']],

            [[
                'slug' => 'cameroon-timber-co',
                'legal_name' => 'Cameroon Timber Co. Sarl',
                'trade_name' => 'Cameroon Timber Co.',
                'logo_path' => 'suppliers/logos/cameroon-timber-co.png',
                'description' => 'Cameroon Timber Co. trades logs, sawn timber, beams and plywood out of Kribi deep-sea port for construction buyers worldwide.',
                'region' => 'South', 'city' => 'Kribi',
                'email' => 'trade@cameroontimberco.example', 'phone' => '+237 6 50 00 00 04',
                'supplier_type' => SupplierType::Trader,
                'response_rate_percent' => 93, 'years_experience' => 12,
            ], ['tali', 'azobe', 'iroko'], ['CN', 'VN', 'IN', 'KR']],

            [[
                'slug' => 'natural-timber-solutions',
                'legal_name' => 'Natural Timber Solutions Sarl',
                'trade_name' => 'Natural Timber Solutions',
                'logo_path' => 'suppliers/logos/natural-timber-solutions.png',
                'description' => 'Natural Timber Solutions processes veneer, plywood, mouldings and sawn timber at its Bafoussam plant for furniture and joinery manufacturers.',
                'region' => 'West', 'city' => 'Bafoussam',
                'email' => 'hello@naturaltimber.example', 'phone' => '+237 6 50 00 00 05',
                'supplier_type' => SupplierType::Manufacturer,
                'response_rate_percent' => 92, 'years_experience' => 7,
            ], ['ayous', 'movingui', 'bosse'], ['FR', 'IT', 'PT']],

            [[
                'slug' => 'atlas-wood-exports',
                'legal_name' => 'Atlas Wood Exports Sarl',
                'trade_name' => 'Atlas Wood Exports',
                'logo_path' => 'suppliers/logos/atlas-wood-exports.png',
                'description' => 'Atlas Wood Exports moves hardwood, sawn timber, logs and beams north out of Garoua to buyers across the Sahel and the Mediterranean.',
                'region' => 'North', 'city' => 'Garoua',
                'email' => 'sales@atlaswood.example', 'phone' => '+237 6 50 00 00 06',
                'supplier_type' => SupplierType::LogisticsProvider,
                'response_rate_percent' => 95, 'years_experience' => 9,
            ], ['tali', 'padouk', 'okan'], ['MA', 'EG', 'TR', 'GR']],

            [[
                'slug' => 'equator-timber',
                'legal_name' => 'Equator Timber Sarl',
                'trade_name' => 'Equator Timber',
                'logo_path' => 'suppliers/logos/equator-timber.png',
                'description' => 'Equator Timber runs a Douala sawmill producing sawn timber, plywood, veneer and logs for export to Asian and European markets.',
                'region' => 'Littoral', 'city' => 'Douala',
                'email' => 'info@equatortimber.example', 'phone' => '+237 6 50 00 00 07',
                'supplier_type' => SupplierType::Manufacturer,
                'response_rate_percent' => 91, 'years_experience' => 8,
            ], ['sapele', 'ayous', 'doussie'], ['CN', 'JP', 'NL']],

            [[
                'slug' => 'cemac-wood-traders',
                'legal_name' => 'CEMAC Wood Traders Sarl',
                'trade_name' => 'CEMAC Wood Traders',
                'logo_path' => 'suppliers/logos/cemac-wood-traders.png',
                'description' => 'CEMAC Wood Traders sources logs, sawn timber, plywood and mouldings across the CEMAC region and consolidates them for export from Yaoundé.',
                'region' => 'Centre', 'city' => 'Yaoundé',
                'email' => 'desk@cemacwood.example', 'phone' => '+237 6 50 00 00 08',
                'supplier_type' => SupplierType::Trader,
                'response_rate_percent' => 89, 'years_experience' => 6,
            ], ['iroko', 'bilinga', 'padouk'], ['GB', 'BE', 'ZA']],
        ];
    }

    /** @param list<string> $speciesSlugs @param list<string> $markets */
    private function makeVisibleCompany(array $attrs, array $speciesSlugs, array $markets, string $ref): Company
    {
        $slug = $attrs['slug'] ?? Str::slug($attrs['trade_name'] ?? $attrs['legal_name']);
        unset($attrs['slug']);

        // Logos live under public/img/suppliers and are extracted from the
        // approved mockups.
        $logo = $attrs['logo_path'] ?? 'suppliers/'.$slug.'.png';
        unset($attrs['logo_path']);

        $company = Company::firstOrCreate(
            ['slug' => $slug],
            array_merge($attrs, [
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'logo_path' => $logo,
                'verified_at' => now(),
            ]),
        );

        // Keep re-runs in sync with the mockup-derived logo path and the
        // directory metrics (idempotent: same input => same row).
        $company->forceFill(array_merge(
            ['logo_path' => $logo],
            array_intersect_key($attrs, array_flip(['supplier_type', 'response_rate_percent', 'years_experience', 'is_featured'])),
        ))->save();

        if (! $company->contacts()->exists()) {
            $company->contacts()->create([
                'name' => 'Export Desk',
                'title' => 'Sales',
                'email' => $attrs['email'] ?? null,
                'phone' => $attrs['phone'] ?? null,
                'is_public' => true,
                'sort_order' => 0,
            ]);
        }

        $company->species()->syncWithoutDetaching(Species::whereIn('slug', $speciesSlugs)->pluck('id'));

        foreach ($markets as $code) {
            $company->exportMarkets()->firstOrCreate(['country_code' => $code]);
        }

        $company->verificationBadges()->firstOrCreate(['reference_code' => $ref], [
            'badge_type' => BadgeType::VerifiedExporter,
            'status' => BadgeStatus::Active,
            'issued_at' => now(),
            'valid_until' => now()->addYear()->toDateString(),
            'is_public' => true,
        ]);

        return $company;
    }
}
