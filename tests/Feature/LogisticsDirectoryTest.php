<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\VerificationStage;
use App\Models\Company;

it('lists only logistics companies', function () {
    $logistics = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics]);
    $supplier = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Supplier]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSee($logistics->name);
    $response->assertDontSee($supplier->name);
});

it('filters the directory by region', function () {
    $centre = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics, 'region' => 'Centre']);
    $littoral = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics, 'region' => 'Littoral']);

    $response = $this->get('/logistics-directory?region=Centre');

    $response->assertSee($centre->name);
    $response->assertDontSee($littoral->name);
});

it('labels a verified logistics company as Trusted', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics]);
    $company->verification()->create(['stage' => VerificationStage::Verified]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Trusted']);
});

it('labels a verified logistics company with a website as Tech-enabled', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics, 'website_url' => 'https://example.com']);
    $company->verification()->create(['stage' => VerificationStage::Published]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Tech-enabled']);
});

it('labels a logistics company with no verification as Unverified', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Unverified']);
});

it('does not label a company with an in-progress verification as Trusted', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified, 'type' => OrganisationType::Logistics]);
    $company->verification()->create(['stage' => VerificationStage::CompanyInfo]);

    $response = $this->get('/logistics-directory');

    $response->assertOk();
    $response->assertSeeInOrder([$company->name, 'Unverified']);
});
