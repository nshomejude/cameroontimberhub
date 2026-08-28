<?php

use App\Models\Company;
use App\Models\CompanyGallery;
use App\Models\Plan;

it('allows creating gallery images up to the plan limit', function () {
    $plan = Plan::factory()->create(['features' => ['max_gallery' => 2]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);

    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'a.jpg']);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'b.jpg']);

    expect($company->gallery()->count())->toBe(2);
});

it('refuses to create a gallery image beyond the plan limit', function () {
    $plan = Plan::factory()->create(['features' => ['max_gallery' => 1]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'a.jpg']);

    expect(fn () => CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'b.jpg']))
        ->toThrow(RuntimeException::class, 'gallery image limit');

    expect($company->gallery()->count())->toBe(1);
});

it('uses the default 3-image limit for a company with no plan', function () {
    $company = Company::factory()->create(['plan_id' => null]);

    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'a.jpg']);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'b.jpg']);
    CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'c.jpg']);

    expect(fn () => CompanyGallery::create(['company_id' => $company->id, 'image_path' => 'd.jpg']))
        ->toThrow(RuntimeException::class);
});
