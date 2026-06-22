<?php

use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\Species;

it('lists publicly visible companies in the directory and hides the rest', function () {
    Company::factory()->publiclyVisible()->create(['legal_name' => 'Visible Exporter Co']);
    Company::factory()->create(['legal_name' => 'Draft Hidden Co']); // draft, not visible

    $this->get(route('directory'))
        ->assertOk()
        ->assertSee('Visible Exporter Co')
        ->assertDontSee('Draft Hidden Co');
});

it('renders a public company profile with verification wording and Organization JSON-LD', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'legal_name' => 'Profile Legal Co',
        'trade_name' => 'ProfileCo',
    ]);

    $this->get(route('companies.show', $company->slug))
        ->assertOk()
        ->assertSee('ProfileCo')
        ->assertSee('Verified profile')
        ->assertSee('Documents reviewed by Cameroon Timber Hub based on information submitted by the company.')
        ->assertSee('"@type":"Organization"', false)
        ->assertDontSee('guaranteed');
});

it('returns 404 for every non-publicly-visible company status', function (CompanyStatus $status) {
    $company = Company::factory()->publiclyVisible()->create();
    $company->update(['status' => $status]);

    $this->get(route('companies.show', $company->slug))->assertNotFound();
})->with([
    'draft' => [CompanyStatus::Draft],
    'pending' => [CompanyStatus::Pending],
    'rejected' => [CompanyStatus::Rejected],
    'suspended' => [CompanyStatus::Suspended],
    'archived' => [CompanyStatus::Archived],
]);

it('serves the species index and a species detail page', function () {
    $species = Species::factory()->create(['common_name' => 'Testwood']);

    $this->get(route('species.index'))->assertOk()->assertSee('Testwood');
    $this->get(route('species.show', $species->slug))->assertOk()->assertSee('Testwood');
});

it('lists only verified exporters on a species page', function () {
    $species = Species::factory()->create(['common_name' => 'Linkwood']);

    $visible = Company::factory()->publiclyVisible()->create(['legal_name' => 'Handles Linkwood Co']);
    $visible->species()->attach($species);

    $hidden = Company::factory()->create(['legal_name' => 'Hidden Handler Co']); // draft
    $hidden->species()->attach($species);

    $this->get(route('species.show', $species->slug))
        ->assertOk()
        ->assertSee('Handles Linkwood Co')
        ->assertDontSee('Hidden Handler Co');
});

it('returns 404 for an unpublished species', function () {
    $species = Species::factory()->unpublished()->create(['common_name' => 'Secretwood']);

    $this->get(route('species.show', $species->slug))->assertNotFound();
});

it('exposes a sitemap with visible companies and a robots file', function () {
    $company = Company::factory()->publiclyVisible()->create();

    $this->get('/sitemap.xml')
        ->assertOk()
        ->assertSee(route('companies.show', $company->slug), false);

    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap:');
});
