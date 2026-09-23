<?php

use App\Models\Company;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function supplierRfqApiUser(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

/** An approved RFQ routed to the given company. */
function apiRouteRfqTo(Company $company, array $attributes = [], string $status = 'sent'): Rfq
{
    $rfq = Rfq::factory()->approved()->create($attributes);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);

    RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => $status,
        'routed_at' => now(),
    ]);

    return $rfq;
}

/* --------------------------------------------------------------- listing */

it('lists only rfqs routed to the caller company', function () {
    [$user, $company] = supplierRfqApiUser();
    $other = Company::factory()->publiclyVisible()->create();

    $mine = apiRouteRfqTo($company);
    apiRouteRfqTo($other);

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/rfqs')
        ->assertOk();

    $references = collect($response->json('data'))->pluck('reference');

    expect($references)->toContain($mine->reference_code)
        ->and($references)->toHaveCount(1);
});

it('filters the inbox by routing status', function () {
    [$user, $company] = supplierRfqApiUser();
    $sent = apiRouteRfqTo($company, [], 'sent');
    $declined = apiRouteRfqTo($company, [], 'declined');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/rfqs?status=declined')
        ->assertOk();

    $references = collect($response->json('data'))->pluck('reference');

    expect($references)->toContain($declined->reference_code)
        ->and($references)->not->toContain($sent->reference_code);
});

it('includes the caller own routing status on each rfq', function () {
    [$user, $company] = supplierRfqApiUser();
    apiRouteRfqTo($company, [], 'viewed');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/rfqs')
        ->assertOk();

    expect($response->json('data.0.routing.status'))->toBe('viewed');
});

/* ----------------------------------------------------------------- show */

it('shows a single rfq routed to the caller company', function () {
    [$user, $company] = supplierRfqApiUser();
    $rfq = apiRouteRfqTo($company);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/rfqs/'.$rfq->reference_code)
        ->assertOk()
        ->assertJsonPath('data.reference', $rfq->reference_code)
        ->assertJsonPath('data.buyer_email', $rfq->buyer_email);
});

it('404s an rfq routed to a different company', function () {
    [$user] = supplierRfqApiUser();
    $other = Company::factory()->publiclyVisible()->create();
    $theirs = apiRouteRfqTo($other);

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/rfqs/'.$theirs->reference_code)
        ->assertNotFound();
});

it('404s an rfq that was never routed to anyone', function () {
    [$user] = supplierRfqApiUser();
    $unrouted = Rfq::factory()->approved()->create();

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/rfqs/'.$unrouted->reference_code)
        ->assertNotFound();
});

/* --------------------------------------------------------------- gating */

it('403s a buyer with no company hitting the supplier rfq inbox', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/supplier/rfqs')
        ->assertForbidden();
});

it('401s a guest on the supplier rfq inbox', function () {
    $this->getJson('/api/v1/supplier/rfqs')->assertUnauthorized();
});

it('401s a guest on a single supplier rfq', function () {
    [$user, $company] = supplierRfqApiUser();
    $rfq = apiRouteRfqTo($company);

    $this->getJson('/api/v1/supplier/rfqs/'.$rfq->reference_code)->assertUnauthorized();
});
