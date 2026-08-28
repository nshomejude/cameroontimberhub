<?php

use App\Exceptions\InvalidGeospatialDataException;
use App\Services\CertificateGeoService;

beforeEach(function () {
    $this->service = new CertificateGeoService;
});

it('validates and hashes a point with at least 6 decimal digits of precision', function () {
    $point = ['type' => 'Point', 'coordinates' => ['11.518890', '3.848210']];

    expect($this->service->hashAndValidate($point))->toHaveLength(64);
});

it('rejects a point with fewer than 6 decimal digits of precision, per EUDR', function () {
    $point = ['type' => 'Point', 'coordinates' => ['11.510000', '3.84']];

    expect(fn () => $this->service->hashAndValidate($point))
        ->toThrow(InvalidGeospatialDataException::class, 'precision');
});

it('rejects a float coordinate, because a float cannot carry the required trailing zeros', function () {
    $point = ['type' => 'Point', 'coordinates' => [11.520000, 3.850000]];

    expect(fn () => $this->service->hashAndValidate($point))
        ->toThrow(InvalidGeospatialDataException::class, 'decimal string');
});

it('validates a closed, simple (non-self-intersecting) polygon', function () {
    $polygon = [
        'type' => 'Polygon',
        'coordinates' => [[
            ['11.518890', '3.848210'],
            ['11.520000', '3.848210'],
            ['11.520000', '3.850000'],
            ['11.518890', '3.850000'],
            ['11.518890', '3.848210'],
        ]],
    ];

    expect($this->service->hashAndValidate($polygon))->toHaveLength(64);
});

it('rejects a polygon ring that is not closed', function () {
    $polygon = [
        'type' => 'Polygon',
        'coordinates' => [[
            ['11.518890', '3.848210'],
            ['11.520000', '3.848210'],
            ['11.520000', '3.850000'],
        ]],
    ];

    expect(fn () => $this->service->hashAndValidate($polygon))
        ->toThrow(InvalidGeospatialDataException::class, 'closed');
});

it('rejects a closed ring with too few distinct vertices to be an area', function () {
    $polygon = [
        'type' => 'Polygon',
        'coordinates' => [[
            ['11.518890', '3.848210'],
            ['11.520000', '3.848210'],
            ['11.518890', '3.848210'],
        ]],
    ];

    expect(fn () => $this->service->hashAndValidate($polygon))
        ->toThrow(InvalidGeospatialDataException::class, 'at least 4 positions');
});

it('rejects a self-intersecting (bowtie) polygon', function () {
    $bowtie = [
        'type' => 'Polygon',
        'coordinates' => [[
            ['11.500000', '3.800000'],
            ['11.600000', '3.900000'],
            ['11.600000', '3.800000'],
            ['11.500000', '3.900000'],
            ['11.500000', '3.800000'],
        ]],
    ];

    expect(fn () => $this->service->hashAndValidate($bowtie))
        ->toThrow(InvalidGeospatialDataException::class, 'self-intersect');
});

it('rejects an unsupported geometry type', function () {
    expect(fn () => $this->service->hashAndValidate(['type' => 'LineString', 'coordinates' => []]))
        ->toThrow(InvalidGeospatialDataException::class, 'Unsupported GeoJSON type');
});

it('produces the same hash for the same geometry regardless of key order', function () {
    $a = ['type' => 'Point', 'coordinates' => ['11.518890', '3.848210']];
    $b = ['coordinates' => ['11.518890', '3.848210'], 'type' => 'Point'];

    expect($this->service->hashAndValidate($a))->toBe($this->service->hashAndValidate($b));
});

it('produces a different hash when a coordinate actually changes', function () {
    $a = ['type' => 'Point', 'coordinates' => ['11.518890', '3.848210']];
    $b = ['type' => 'Point', 'coordinates' => ['11.518891', '3.848210']];

    expect($this->service->hashAndValidate($a))->not->toBe($this->service->hashAndValidate($b));
});
