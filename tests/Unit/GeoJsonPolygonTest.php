<?php

use App\Support\GeoJsonPolygon;

it('accepts a valid closed polygon ring', function () {
    $geojson = [
        'type' => 'Polygon',
        'coordinates' => [[
            [9.7, 4.05],
            [9.71, 4.05],
            [9.71, 4.06],
            [9.7, 4.05],
        ]],
    ];

    expect(GeoJsonPolygon::isValid($geojson))->toBeTrue();
});

it('rejects a non-Polygon type', function () {
    expect(GeoJsonPolygon::isValid([
        'type' => 'Point',
        'coordinates' => [9.7, 4.05],
    ]))->toBeFalse();
});

it('rejects missing coordinates', function () {
    expect(GeoJsonPolygon::isValid(['type' => 'Polygon']))->toBeFalse();
});

it('rejects a ring with fewer than 4 points', function () {
    expect(GeoJsonPolygon::isValid([
        'type' => 'Polygon',
        'coordinates' => [[
            [9.7, 4.05],
            [9.71, 4.05],
            [9.7, 4.05],
        ]],
    ]))->toBeFalse();
});

it('rejects a ring whose first and last points do not match', function () {
    expect(GeoJsonPolygon::isValid([
        'type' => 'Polygon',
        'coordinates' => [[
            [9.7, 4.05],
            [9.71, 4.05],
            [9.71, 4.06],
            [9.72, 4.07],
        ]],
    ]))->toBeFalse();
});

it('rejects a ring with a non-numeric coordinate', function () {
    expect(GeoJsonPolygon::isValid([
        'type' => 'Polygon',
        'coordinates' => [[
            [9.7, 4.05],
            ['bad', 4.05],
            [9.71, 4.06],
            [9.7, 4.05],
        ]],
    ]))->toBeFalse();
});

it('rejects empty coordinates array', function () {
    expect(GeoJsonPolygon::isValid([
        'type' => 'Polygon',
        'coordinates' => [],
    ]))->toBeFalse();
});
