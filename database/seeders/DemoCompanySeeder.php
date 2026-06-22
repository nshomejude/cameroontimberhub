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
        $company = Company::firstOrCreate(
            ['slug' => Str::slug($attrs['trade_name'] ?? $attrs['legal_name'])],
            array_merge($attrs, [
                'status' => CompanyStatus::Verified,
                'country_code' => 'CM',
                'logo_path' => 'companies/demo/'.Str::slug($attrs['legal_name']).'.png',
                'verified_at' => now(),
            ]),
        );

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
