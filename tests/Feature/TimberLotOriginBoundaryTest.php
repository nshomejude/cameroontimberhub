<?php

use App\Models\Company;
use App\Models\TimberLot;

function validBoundaryGeoJson(): array
{
    return [
        'type' => 'Polygon',
        'coordinates' => [[
            [9.70, 4.05],
            [9.71, 4.05],
            [9.71, 4.06],
            [9.70, 4.05],
        ]],
    ];
}

it('stores and retrieves a valid GeoJSON polygon boundary', function () {
    $lot = TimberLot::factory()->for(Company::factory()->create())->create([
        'origin_boundary' => validBoundaryGeoJson(),
    ]);

    $lot->refresh();

    expect($lot->origin_boundary)->toBe(validBoundaryGeoJson());
});

it('allows a null boundary', function () {
    $lot = TimberLot::factory()->for(Company::factory()->create())->create([
        'origin_boundary' => null,
    ]);

    expect($lot->refresh()->origin_boundary)->toBeNull();
});

it('rejects an invalid GeoJSON polygon boundary', function () {
    $lot = TimberLot::factory()->for(Company::factory()->create())->make();

    expect(fn () => $lot->origin_boundary = ['type' => 'Point', 'coordinates' => [9.7, 4.05]])
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a polygon ring that is not closed', function () {
    $lot = TimberLot::factory()->for(Company::factory()->create())->make();

    expect(fn () => $lot->origin_boundary = [
        'type' => 'Polygon',
        'coordinates' => [[[9.7, 4.05], [9.71, 4.05], [9.71, 4.06], [9.72, 4.07]]],
    ])->toThrow(InvalidArgumentException::class);
});
