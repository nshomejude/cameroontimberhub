<?php

use App\Models\Company;
use App\Models\Plan;

it('reads the numeric max_gallery limit from the active plan', function () {
    $plan = Plan::factory()->create(['features' => ['max_gallery' => 10]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);

    expect($company->maxGalleryImages())->toBe(10);
});

it('defaults to 3 gallery images when the company has no plan at all', function () {
    $company = Company::factory()->create(['plan_id' => null]);

    expect($company->maxGalleryImages())->toBe(3);
});

it('defaults to 3 when the assigned plan has no max_gallery key set', function () {
    $plan = Plan::factory()->create(['features' => ['verified_badge' => true]]);
    $company = Company::factory()->create(['plan_id' => $plan->id]);

    expect($company->maxGalleryImages())->toBe(3);
});
