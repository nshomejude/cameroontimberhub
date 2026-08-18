<?php

namespace Database\Seeders;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
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
        ], ['ayous', 'sapele', 'movingui'], ['CN', 'US', 'GB', 'FR', 'ZA', 'EG'], 'CTH-DEMO-0005');

        // A draft company — must never surface publicly (visibility-gate demo).
        Company::firstOrCreate(['slug' => 'pending-mill'], [
            'legal_name' => 'Pending Mill Sarl',
            'status' => CompanyStatus::Draft,
            'description' => 'A company still completing onboarding; not yet verified.',
            'region' => 'Centre',
            'city' => 'Yaoundé',
        ]);
    }

    /** @param list<string> $speciesSlugs @param list<string> $markets */
    private function makeVisibleCompany(array $attrs, array $speciesSlugs, array $markets, string $ref): Company
    {
        $slug = Str::slug($attrs['trade_name'] ?? $attrs['legal_name']);

        $company = Company::firstOrCreate(
            ['slug' => $slug],
            array_merge($attrs, [
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                // Logos live under public/img/suppliers and are extracted from the
                // approved landing-page mockup.
                'logo_path' => 'suppliers/'.$slug.'.png',
                'verified_at' => now(),
            ]),
        );

        // Keep re-runs in sync with the mockup-derived logo path.
        $company->forceFill(['logo_path' => 'suppliers/'.$slug.'.png'])->save();

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
