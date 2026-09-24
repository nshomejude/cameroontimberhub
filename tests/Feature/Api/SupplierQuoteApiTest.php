<?php

use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function supplierQuoteApiUser(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

function routeApprovedRfqTo(Company $company): Rfq
{
    $rfq = Rfq::factory()->approved()->create();
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);

    RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    return $rfq;
}

function validQuotePayload(): array
{
    return [
        'currency' => 'USD',
        'incoterm' => 'CIF',
        'lead_time_days' => 30,
        'validity_days' => 14,
        'payment_terms' => '30% advance, 70% on delivery',
        'notes' => 'Kiln dried, ready to ship.',
        'items' => [
            [
                'description' => 'Sapele sawn timber',
                'quantity' => 50,
                'unit' => 'm3',
                'unit_price' => 185.00,
            ],
        ],
    ];
}

/* --------------------------------------------------------------- submit */

it('submits a valid quote against a routed rfq', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $response = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated();

    $reference = $response->json('data.reference');

    expect($response->json('data.status'))->toBe('submitted')
        ->and($response->json('data.total_amount'))->toBe('9250.00');

    $quote = Quote::where('reference_code', $reference)->firstOrFail();
    expect($quote->status)->toBe(QuoteStatus::Submitted)
        ->and($quote->company_id)->toBe($company->getKey())
        ->and($quote->rfq_id)->toBe($rfq->getKey());
});

it('the submitted quote appears in the caller own quotes list', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated();

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/quotes')
        ->assertOk();

    expect(collect($response->json('data'))->pluck('rfq_reference'))->toContain($rfq->reference_code);
});

it('404s quoting an rfq not routed to the caller company', function () {
    [$user] = supplierQuoteApiUser();
    $rfq = Rfq::factory()->approved()->create();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertNotFound();
});

it('blocks a second quote on the same rfq from the same company', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertStatus(409);
});

it('422s a submission missing required fields', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', ['items' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items', 'error.details');
});

it('422s a submission with an item missing quantity and unit price', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $payload = validQuotePayload();
    unset($payload['items'][0]['quantity'], $payload['items'][0]['unit_price']);

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items.0.quantity', 'items.0.unit_price'], 'error.details');
});

/* --------------------------------------------------------------- gating */

it('403s a buyer with no company submitting a quote', function () {
    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->approved()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertForbidden();
});

it('401s a guest submitting a quote', function () {
    $rfq = Rfq::factory()->approved()->create();

    $this->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertUnauthorized();
});

it('401s a guest listing supplier quotes', function () {
    $this->getJson('/api/v1/supplier/quotes')->assertUnauthorized();
});

/* ------------------------------------------------------------------ show */

it('shows full detail for the caller own quote', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $reference = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated()
        ->json('data.reference');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/quotes/'.$reference)
        ->assertOk()
        ->assertJsonPath('data.reference', $reference)
        ->assertJsonPath('data.rfq_reference', $rfq->reference_code)
        ->assertJsonCount(1, 'data.items');
});

it('supplier quote detail includes internals the buyer resource omits', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $reference = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated()
        ->json('data.reference');

    $response = $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/quotes/'.$reference)
        ->assertOk();

    // QuoteResource's own docblock says rfq_company_id/revision are
    // deliberately NOT part of the buyer payload — the supplier view carries
    // them.
    expect($response->json('data'))->toHaveKeys(['rfq_company_id', 'revision']);
});

it('404s fetching another company quote by reference', function () {
    [$user] = supplierQuoteApiUser();
    [, $otherCompany] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($otherCompany);

    $otherUser = User::factory()->create();
    $otherCompany->users()->attach($otherUser);

    $reference = $this->actingAs($otherUser, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated()
        ->json('data.reference');

    $this->actingAs($user, 'sanctum')
        ->getJson('/api/v1/supplier/quotes/'.$reference)
        ->assertNotFound();
});

it('401s a guest fetching a supplier quote', function () {
    $this->getJson('/api/v1/supplier/quotes/QTE-DOES-NOT-EXIST')->assertUnauthorized();
});

/* -------------------------------------------------------------- withdraw */

it('withdraws the caller own quote', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $reference = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated()
        ->json('data.reference');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/quotes/'.$reference.'/withdraw', ['reason' => 'Pricing changed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'withdrawn');

    $quote = Quote::where('reference_code', $reference)->firstOrFail();
    expect($quote->status)->toBe(QuoteStatus::Withdrawn);
});

it('409s withdrawing an already withdrawn quote', function () {
    [$user, $company] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($company);

    $reference = $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated()
        ->json('data.reference');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/quotes/'.$reference.'/withdraw')
        ->assertOk();

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/quotes/'.$reference.'/withdraw')
        ->assertStatus(409);
});

it('404s withdrawing another company quote', function () {
    [$user] = supplierQuoteApiUser();
    [, $otherCompany] = supplierQuoteApiUser();
    $rfq = routeApprovedRfqTo($otherCompany);

    $otherUser = User::factory()->create();
    $otherCompany->users()->attach($otherUser);

    $reference = $this->actingAs($otherUser, 'sanctum')
        ->postJson('/api/v1/supplier/rfqs/'.$rfq->reference_code.'/quote', validQuotePayload())
        ->assertCreated()
        ->json('data.reference');

    $this->actingAs($user, 'sanctum')
        ->postJson('/api/v1/supplier/quotes/'.$reference.'/withdraw')
        ->assertNotFound();
});
