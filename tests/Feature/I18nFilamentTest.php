<?php

use App\Enums\CompanyUserRole;
use App\Enums\OrganisationType;
use App\Filament\Exporter\Pages\BuyerProcurementWorkspace;
use App\Filament\Pages\ComplianceOfficerWorkspace;
use App\Filament\Pages\InspectorWorkspace;
use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyDocument;
use App\Models\CompanyGallery;
use App\Models\Inspector;
use App\Models\Product;
use App\Models\Species;
use App\Models\User;
use App\Models\VerificationRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function i18nExporterMember(): array
{
    // Every onboarding-checklist ingredient so RedirectIncompleteOnboarding
    // does not intercept the /dashboard landing request.
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::Supplier,
        'profile_completion' => 100,
    ]);
    CompanyContact::factory()->create(['company_id' => $company->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'gallery/test.jpg']);
    CompanyDocument::factory()->create(['company_id' => $company->id]);
    VerificationRequest::factory()->create(['company_id' => $company->id]);
    $company->species()->attach(Species::factory()->create());

    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    return [$user, $company];
}

function assertNoFilamentKeyLeak($response): void
{
    expect($response->getContent())->not->toContain('messages.filament.');
}

/* ------------------------------------------------------------- admin panel */

dataset('locales', ['en', 'fr']);

it('renders admin panel index and key resource lists with no key leak', function (string $locale) {
    $this->actingAs(staff('super_admin'));

    foreach (['/admin', '/admin/species', '/admin/companies', '/admin/users', '/admin/orders', '/admin/disputes', '/admin/inquiries'] as $path) {
        $res = $this->withSession(['locale' => $locale])->get($path);
        $res->assertOk();
        assertNoFilamentKeyLeak($res);
    }
})->with('locales');

it('translates a known admin nav label per locale', function (string $locale, string $expected) {
    $this->actingAs(staff('super_admin'));

    $this->withSession(['locale' => $locale])->get('/admin')->assertOk()->assertSee($expected);
})->with([
    'en' => ['en', 'Users'],
    'fr' => ['fr', 'Utilisateurs'],
]);

/* ---------------------------------------------------------- exporter panel */

it('renders exporter dashboard, products and company edit with no key leak', function (string $locale) {
    [$user, $company] = i18nExporterMember();
    Product::factory()->for($company, 'company')->create();

    $this->actingAs($user);

    foreach (['/dashboard', '/dashboard/products', '/dashboard/companies/'.$company->slug.'/edit'] as $path) {
        $res = $this->withSession(['locale' => $locale])->get($path);
        $res->assertOk();
        assertNoFilamentKeyLeak($res);
    }
})->with('locales');

it('translates the exporter Products nav label per locale', function (string $locale, string $expected) {
    [$user] = i18nExporterMember();

    $this->actingAs($user);

    $this->withSession(['locale' => $locale])->get('/dashboard')->assertOk()->assertSee($expected);
})->with([
    'en' => ['en', 'Products'],
    'fr' => ['fr', 'Produits'],
]);

/* -------------------------------------------------------- custom workspaces */

it('renders the compliance officer workspace in French', function () {
    $this->actingAs(staff('admin'));

    $res = $this->withSession(['locale' => 'fr'])->get(ComplianceOfficerWorkspace::getUrl());
    $res->assertOk();
    assertNoFilamentKeyLeak($res);
});

it('renders the inspector workspace in French', function () {
    $user = staff('compliance_officer');
    Inspector::create([
        'user_id' => $user->getKey(),
        'status' => 'active',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
    ]);

    $res = $this->actingAs($user)->withSession(['locale' => 'fr'])->get(InspectorWorkspace::getUrl());
    $res->assertOk();
    assertNoFilamentKeyLeak($res);
});

it('renders the buyer procurement workspace in French', function () {
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create(['type' => OrganisationType::Buyer]);
    $company->users()->attach($user, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    $res = $this->actingAs($user)->withSession(['locale' => 'fr'])->get(BuyerProcurementWorkspace::getUrl(panel: 'exporter'));
    $res->assertOk();
    assertNoFilamentKeyLeak($res);
});
