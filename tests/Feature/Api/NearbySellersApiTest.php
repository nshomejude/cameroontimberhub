<?php

use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use App\Support\Geo\SeedCompanyCoordinates;

// Douala city centre — the query point for most tests.
const DLA_LAT = 4.0511;
const DLA_LNG = 9.7679;

function nearbyCompany(float $lat, float $lng, array $attrs = []): Company
{
    return Company::factory()->publiclyVisible()->create(array_merge([
        'latitude' => $lat,
        'longitude' => $lng,
        'type' => OrganisationType::Supplier->value,
    ], $attrs));
}

it('returns public sellers ordered by distance with the card payload', function () {
    $yaounde = nearbyCompany(3.8480, 11.5021, ['city' => 'Yaoundé', 'address_line' => 'Quartier Nsam']); // ~200 km
    $douala = nearbyCompany(4.0450, 9.7000, ['city' => 'Douala']);  // ~7.5 km
    $edea = nearbyCompany(3.7976, 10.1329, ['city' => 'Edéa']);     // ~50 km

    $response = $this->getJson('/api/v1/nearby?lat='.DLA_LAT.'&lng='.DLA_LNG.'&radius_km=300')->assertOk();

    expect(collect($response->json('data'))->pluck('id')->all())
        ->toBe([$douala->id, $edea->id, $yaounde->id]);

    $first = $response->json('data.0');
    expect($first)->toHaveKeys(['id', 'slug', 'name', 'logo_url', 'city', 'region', 'country_code', 'verified_at', 'supplier_type', 'rating', 'type', 'latitude', 'longitude', 'distance_km'])
        ->and($first['type'])->toBe('supplier')
        ->and($first['latitude'])->toBe(4.045)
        ->and($first['distance_km'])->toBeGreaterThan(7.0)->toBeLessThan(8.0)
        ->and(round($first['distance_km'], 1))->toBe($first['distance_km']);

    expect($response->json('data.2.address'))->toBe('Quartier Nsam')
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta'))->toHaveKeys(['current_page', 'per_page', 'last_page']);
});

it('applies the default 100 km radius and a custom radius', function () {
    $near = nearbyCompany(4.0450, 9.7000);
    nearbyCompany(3.8480, 11.5021); // Yaoundé, ~200 km

    $ids = collect($this->getJson('/api/v1/nearby?lat='.DLA_LAT.'&lng='.DLA_LNG)->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toBe([$near->id]);

    $ids = collect($this->getJson('/api/v1/nearby?lat='.DLA_LAT.'&lng='.DLA_LNG.'&radius_km=5')->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toBe([]);
});

it('filters by organisation types', function () {
    $processor = nearbyCompany(4.05, 9.70, ['type' => OrganisationType::Processor->value]);
    $artisan = nearbyCompany(4.06, 9.71, ['type' => OrganisationType::Artisan->value]);
    nearbyCompany(4.07, 9.72, ['type' => OrganisationType::Retailer->value]);
    nearbyCompany(4.04, 9.73, ['type' => OrganisationType::Logistics->value]); // never a seller

    $ids = collect($this->getJson('/api/v1/nearby?lat='.DLA_LAT.'&lng='.DLA_LNG.'&types[]=processor&types[]=artisan')
        ->assertOk()->json('data'))->pluck('id')->sort()->values();
    expect($ids->all())->toBe(collect([$processor->id, $artisan->id])->sort()->values()->all());

    // No types: every seller type (the logistics company is excluded).
    expect($this->getJson('/api/v1/nearby?lat='.DLA_LAT.'&lng='.DLA_LNG)->json('meta.total'))->toBe(3);
});

it('excludes unverified, not publicly visible and coordinate-less companies', function () {
    $visible = nearbyCompany(4.05, 9.70);
    Company::factory()->create(['latitude' => 4.05, 'longitude' => 9.71, 'type' => 'supplier']); // not visible
    Company::factory()->verified()->create(['latitude' => 4.05, 'longitude' => 9.72, 'type' => 'supplier']); // verified but incomplete
    nearbyCompany(4.05, 9.73, ['status' => CompanyStatus::Pending]);
    Company::factory()->publiclyVisible()->create(['latitude' => null, 'longitude' => null, 'type' => 'supplier']);

    $ids = collect($this->getJson('/api/v1/nearby?lat='.DLA_LAT.'&lng='.DLA_LNG)->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$visible->id]);
});

it('validates nearby parameters', function (string $query, array $errors) {
    $this->getJson('/api/v1/nearby'.$query)->assertUnprocessable()->assertJsonValidationErrors($errors, 'error.details');
})->with([
    'missing point' => ['', ['lat', 'lng']],
    'lat out of range' => ['?lat=91&lng=9', ['lat']],
    'lng out of range' => ['?lat=4&lng=181', ['lng']],
    'bad type' => ['?lat=4&lng=9&types[]=buyer', ['types.0']],
    'radius too large' => ['?lat=4&lng=9&radius_km=1001', ['radius_km']],
    'per_page too large' => ['?lat=4&lng=9&per_page=51', ['per_page']],
]);

it('paginates nearby results', function () {
    foreach (range(1, 3) as $i) {
        nearbyCompany(4.05 + $i / 100, 9.70);
    }

    $response = $this->getJson('/api/v1/nearby?lat='.DLA_LAT.'&lng='.DLA_LNG.'&per_page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.last_page'))->toBe(2);
});

it('adds distance_km to local listings and sorts by nearest', function () {
    $far = nearbyCompany(3.8480, 11.5021);
    $near = nearbyCompany(4.0450, 9.7000);
    $noCoords = Company::factory()->publiclyVisible()->create(['latitude' => null, 'longitude' => null]);

    $pFar = Product::factory()->create(['company_id' => $far->id, 'status' => ProductStatus::Active]);
    $pNear = Product::factory()->create(['company_id' => $near->id, 'status' => ProductStatus::Active]);
    $pNone = Product::factory()->create(['company_id' => $noCoords->id, 'status' => ProductStatus::Active]);

    $data = collect($this->getJson('/api/v1/local/listings?lat='.DLA_LAT.'&lng='.DLA_LNG.'&sort=nearest')->assertOk()->json('data'));

    expect($data->pluck('id')->all())->toBe([$pNear->id, $pFar->id, $pNone->id])
        ->and($data[0]['distance_km'])->toBeGreaterThan(7.0)->toBeLessThan(8.0)
        ->and($data[1]['distance_km'])->toBeGreaterThan(150)
        ->and($data[2]['distance_km'])->toBeNull()
        ->and($data[0]['supplier']['latitude'])->toBe(4.045);
});

it('leaves local listings unchanged without a point', function () {
    $company = nearbyCompany(4.0450, 9.7000);
    Product::factory()->create(['company_id' => $company->id, 'status' => ProductStatus::Active]);

    $row = $this->getJson('/api/v1/local/listings?sort=nearest')->assertOk()->json('data.0');

    expect($row)->not->toHaveKey('distance_km');

    $this->getJson('/api/v1/local/listings?lat=4.05')->assertUnprocessable()->assertJsonValidationErrors(['lng'], 'error.details');
});

it('exposes coordinates and address on supplier and yard detail only when present', function () {
    $with = nearbyCompany(4.0450, 9.7000, ['address_line' => 'Zone Portuaire']);
    $without = Company::factory()->publiclyVisible()->create(['latitude' => null, 'longitude' => null, 'address_line' => null]);

    $this->getJson("/api/v1/suppliers/{$with->slug}")->assertOk()
        ->assertJsonPath('data.latitude', 4.045)
        ->assertJsonPath('data.longitude', 9.7)
        ->assertJsonPath('data.address', 'Zone Portuaire');

    $this->getJson("/api/v1/local/yards/{$with->slug}")->assertOk()->assertJsonPath('data.latitude', 4.045);

    expect($this->getJson("/api/v1/suppliers/{$without->slug}")->assertOk()->json('data'))
        ->not->toHaveKeys(['latitude', 'longitude', 'address']);
});

it('backfills seeded company coordinates idempotently', function () {
    $company = Company::factory()->create(['slug' => 'sifor-timber', 'latitude' => null, 'longitude' => null, 'address_line' => null]);
    $custom = Company::factory()->create(['slug' => 'kuete-timber', 'latitude' => 1.5, 'longitude' => 2.5]);

    $this->artisan('companies:backfill-coordinates')->assertSuccessful();
    $this->artisan('companies:backfill-coordinates')->expectsOutput('Updated 0 companies.')->assertSuccessful();

    [$lat, $lng, $address] = SeedCompanyCoordinates::MAP['sifor-timber'];
    expect((float) $company->fresh()->latitude)->toBe($lat)
        ->and((float) $company->fresh()->longitude)->toBe($lng)
        ->and($company->fresh()->address_line)->toBe($address)
        ->and((float) $custom->fresh()->latitude)->toBe(1.5);

    $this->artisan('companies:backfill-coordinates --force')->assertSuccessful();
    expect((float) $custom->fresh()->latitude)->toBe(SeedCompanyCoordinates::MAP['kuete-timber'][0]);
});
