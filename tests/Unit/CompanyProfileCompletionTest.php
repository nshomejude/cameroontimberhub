<?php

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyDocument;
use App\Models\CompanyGallery;
use App\Models\Species;
use App\Models\VerificationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('scores a bare company low', function () {
    $company = Company::factory()->create([
        'description' => 'short',
        'region' => null,
        'city' => null,
        'website_url' => null,
        'email' => null,
        'phone' => null,
    ]);

    expect($company->calculateProfileCompletion())->toBeLessThan(60);
});

it('scores a fully-profiled, verified company at or near 100', function () {
    $company = Company::factory()->create();

    CompanyContact::factory()->create(['company_id' => $company->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'gallery/test.jpg']);
    CompanyDocument::factory()->create(['company_id' => $company->id]);
    VerificationRequest::factory()->create(['company_id' => $company->id]);
    $company->species()->attach(Species::factory()->create());

    expect($company->calculateProfileCompletion())->toBeGreaterThanOrEqual(90);
});
