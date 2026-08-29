<?php

use App\Models\Company;
use App\Models\CompanyGallery;

it('stores portfolio metadata on a company gallery row', function () {
    $company = Company::factory()->create();

    $item = CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/carved-stool.jpg',
        'caption' => 'Hand-carved bubinga stool',
        'description' => 'A commissioned three-legged stool carved from reclaimed bubinga offcuts.',
        'materials_used' => 'Bubinga, beeswax finish',
        'completed_on' => '2026-03-15',
        'is_portfolio' => true,
        'sort_order' => 0,
    ]);

    expect($item->fresh())
        ->description->toBe('A commissioned three-legged stool carved from reclaimed bubinga offcuts.')
        ->materials_used->toBe('Bubinga, beeswax finish')
        ->is_portfolio->toBeTrue()
        ->and($item->fresh()->completed_on->toDateString())->toBe('2026-03-15');
});

it('defaults is_portfolio to false for plain marketing gallery rows', function () {
    $company = Company::factory()->create();

    $item = CompanyGallery::create([
        'company_id' => $company->id,
        'image_path' => 'gallery/showroom.jpg',
        'caption' => 'Our showroom',
        'sort_order' => 0,
    ]);

    expect($item->fresh()->is_portfolio)->toBeFalse();
});
