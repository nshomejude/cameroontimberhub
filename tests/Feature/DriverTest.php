<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Driver;

it('creates a driver owned by a company', function () {
    $company = Company::factory()->create();
    $driver = Driver::factory()->for($company)->create();

    expect($driver->company->id)->toBe($company->id);
    expect($driver->is_active)->toBeTrue();
});

it('lets a driver carry documents via the shared Document store', function () {
    $driver = Driver::factory()->create();

    $document = Document::factory()->for($driver, 'owner')->create([
        'type' => 'driving_license',
        'expires_at' => now()->addDays(20),
    ]);

    expect($driver->documents)->toHaveCount(1);
    expect($driver->documents->first()->id)->toBe($document->id);
});
