<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\CompanyGallery;

it('shows portfolio items for an artisan company', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::Artisan,
    ]);

    CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/carved-stool.jpg',
        'caption' => 'Hand-carved bubinga stool',
        'description' => 'A commissioned three-legged stool carved from reclaimed bubinga offcuts.',
        'materials_used' => 'Bubinga, beeswax finish',
        'completed_on' => '2026-03-15',
        'is_portfolio' => true,
        'sort_order' => 0,
    ]);

    CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/showroom.jpg',
        'caption' => 'Our showroom',
        'is_portfolio' => false,
        'sort_order' => 1,
    ]);

    $response = $this->get(route('companies.portfolio', $company->slug));

    $response->assertOk();
    $response->assertSee('Hand-carved bubinga stool');
    $response->assertSee('Bubinga, beeswax finish');
    $response->assertDontSee('Our showroom');
});

it('404s the portfolio page for a non-artisan company', function () {
    $company = Company::factory()->publiclyVisible()->create([
        'type' => OrganisationType::Supplier,
    ]);

    $this->get(route('companies.portfolio', $company->slug))->assertNotFound();
});

it('404s the portfolio page for a company not publicly visible', function () {
    $company = Company::factory()->create([
        'status' => CompanyStatus::Draft,
        'type' => OrganisationType::Artisan,
    ]);

    $this->get(route('companies.portfolio', $company->slug))->assertNotFound();
});
