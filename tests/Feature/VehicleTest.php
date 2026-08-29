<?php

use App\Models\Company;
use App\Models\Document;
use App\Models\Vehicle;

it('creates a vehicle owned by a company', function () {
    $company = Company::factory()->create();
    $vehicle = Vehicle::factory()->for($company)->create();

    expect($vehicle->company->id)->toBe($company->id);
    expect($vehicle->is_active)->toBeTrue();
});

it('lets a vehicle carry documents via the shared Document store', function () {
    $vehicle = Vehicle::factory()->create();

    $document = Document::factory()->for($vehicle, 'owner')->create([
        'type' => 'registration_certificate',
        'expires_at' => now()->addDays(20),
    ]);

    expect($vehicle->documents)->toHaveCount(1);
    expect($vehicle->documents->first()->id)->toBe($document->id);
});
