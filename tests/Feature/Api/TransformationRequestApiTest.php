<?php

use App\Enums\OrganisationType;
use App\Models\Company;
use App\Models\LotTransformation;
use App\Models\Species;
use App\Models\User;
use App\Notifications\TransformationRequestAcceptedNotification;
use App\Notifications\TransformationRequestCompletedNotification;
use App\Notifications\TransformationRequestCreatedNotification;
use App\Notifications\TransformationRequestQuotedNotification;
use Illuminate\Support\Facades\Notification;

/** A requester company (any organisation type) + one member user. */
function xfrRequester(array $attributes = []): array
{
    $company = Company::factory()->verified()->create($attributes);
    $user = User::factory()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

/** A verified processor/manufacturer provider company + one member user. */
function xfrProvider(string $type = 'processor'): array
{
    $company = Company::factory()->verified()->create(['type' => $type]);
    $user = User::factory()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

function xfrCreatePayload(Company $provider, array $overrides = []): array
{
    return array_merge([
        'provider_slug' => $provider->slug,
        'service' => 'sawing',
        'volume_m3' => 12.5,
        'input_description' => 'Rough sawn logs',
        'target_spec' => '25mm planks',
        'notes' => 'Please prioritise',
    ], $overrides);
}

/* -------------------------------------------------------------- creation */

it('creates a transformation request against a verified processor', function () {
    [$requesterUser, $requesterCompany] = xfrRequester();
    [, $providerCompany] = xfrProvider();

    Notification::fake();

    $response = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    expect($response->json('data.status'))->toBe('pending');
    expect($response->json('data.requester.slug'))->toBe($requesterCompany->slug);
    expect($response->json('data.provider.slug'))->toBe($providerCompany->slug);
    expect($response->json('data.reference'))->toStartWith('CTH-XFR-');

    Notification::assertSentTo($providerCompany->users, TransformationRequestCreatedNotification::class);
});

it('validates the create payload', function () {
    [$requesterUser] = xfrRequester();

    $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', ['service' => 'sawing'])
        ->assertStatus(422);
});

it('refuses a provider that is not a verified processor or manufacturer', function () {
    [$requesterUser] = xfrRequester();
    $unverified = Company::factory()->create(['type' => 'processor']); // not verified

    $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($unverified))
        ->assertStatus(409);
});

it('refuses a provider that is not a processor or manufacturer type', function () {
    [$requesterUser] = xfrRequester();
    $wrongType = Company::factory()->verified()->create(['type' => OrganisationType::Supplier->value]);

    $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($wrongType))
        ->assertStatus(409);
});

/* ---------------------------------------------------------------- happy path */

it('runs the full happy path from creation to a recorded LotTransformation', function () {
    Notification::fake();

    [$requesterUser, $requesterCompany] = xfrRequester();
    [$providerUser, $providerCompany] = xfrProvider();
    $species = Species::factory()->create();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany, ['species_slug' => $species->slug]))
        ->assertCreated();

    $reference = $create->json('data.reference');

    Notification::assertSentTo($providerCompany->users, TransformationRequestCreatedNotification::class);

    // Provider quotes.
    $quote = $this->actingAs($providerUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/quote", [
            'amount' => 500000,
            'currency' => 'xaf',
            'lead_time_days' => 5,
            'notes' => 'Standard turnaround',
        ])
        ->assertOk();

    expect($quote->json('data.status'))->toBe('quoted');
    expect($quote->json('data.quote.currency'))->toBe('XAF');

    Notification::assertSentTo($requesterCompany->users, TransformationRequestQuotedNotification::class);

    // Requester accepts the quote.
    $accept = $this->actingAs($requesterUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/accept-quote")
        ->assertOk();

    expect($accept->json('data.status'))->toBe('accepted');

    Notification::assertSentTo($providerCompany->users, TransformationRequestAcceptedNotification::class);

    // Provider starts the job.
    $start = $this->actingAs($providerUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/start")
        ->assertOk();

    expect($start->json('data.status'))->toBe('in_progress');

    // Provider completes the job.
    $complete = $this->actingAs($providerUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/complete", [
            'output_volume_m3' => 11.0,
        ])
        ->assertOk();

    expect($complete->json('data.status'))->toBe('completed');
    expect($complete->json('data.lot_transformation_id'))->not->toBeNull();

    $ledger = LotTransformation::find($complete->json('data.lot_transformation_id'));

    expect($ledger)->not->toBeNull();
    expect((int) $ledger->processor_company_id)->toBe($providerCompany->id);
    expect((float) $ledger->output_volume_m3)->toBe(11.0);

    Notification::assertSentTo($requesterCompany->users, TransformationRequestCompletedNotification::class);
});

it('lets the provider accept directly without a quote step', function () {
    [$requesterUser] = xfrRequester();
    [$providerUser, $providerCompany] = xfrProvider();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    $reference = $create->json('data.reference');

    $accept = $this->actingAs($providerUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/accept")
        ->assertOk();

    expect($accept->json('data.status'))->toBe('accepted');
});

/* -------------------------------------------------------------------- decline */

it('lets the provider decline with a reason', function () {
    [$requesterUser] = xfrRequester();
    [$providerUser, $providerCompany] = xfrProvider();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    $reference = $create->json('data.reference');

    $decline = $this->actingAs($providerUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/decline", ['reason' => 'No capacity this month'])
        ->assertOk();

    expect($decline->json('data.status'))->toBe('declined');
});

/* -------------------------------------------------------- wrong-side / 403s */

it('403s the requester attempting a provider-only action', function () {
    [$requesterUser] = xfrRequester();
    [, $providerCompany] = xfrProvider();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    $reference = $create->json('data.reference');

    $this->actingAs($requesterUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/accept")
        ->assertStatus(403);
});

it('403s the provider attempting a requester-only action', function () {
    [$requesterUser] = xfrRequester();
    [$providerUser, $providerCompany] = xfrProvider();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    $reference = $create->json('data.reference');

    $this->actingAs($providerUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/cancel")
        ->assertStatus(403);
});

/* --------------------------------------------------------- non-participant */

it('404s a non-participant company', function () {
    [$requesterUser] = xfrRequester();
    [, $providerCompany] = xfrProvider();
    [$strangerUser] = xfrRequester();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    $reference = $create->json('data.reference');

    $this->actingAs($strangerUser, 'sanctum')
        ->getJson("/api/v1/transformation/requests/{$reference}")
        ->assertStatus(404);
});

/* ----------------------------------------------------------- box filtering */

it('filters by box=sent and box=received', function () {
    [$requesterUser, $requesterCompany] = xfrRequester();
    [$providerUser, $providerCompany] = xfrProvider();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    $reference = $create->json('data.reference');

    $sent = $this->actingAs($requesterUser, 'sanctum')
        ->getJson('/api/v1/transformation/requests?box=sent')
        ->assertOk();

    expect(collect($sent->json('data'))->pluck('reference'))->toContain($reference);

    $received = $this->actingAs($providerUser, 'sanctum')
        ->getJson('/api/v1/transformation/requests?box=received')
        ->assertOk();

    expect(collect($received->json('data'))->pluck('reference'))->toContain($reference);

    // A requester's own "received" box does not show a request it only sent.
    $requesterReceived = $this->actingAs($requesterUser, 'sanctum')
        ->getJson('/api/v1/transformation/requests?box=received')
        ->assertOk();

    expect(collect($requesterReceived->json('data'))->pluck('reference'))->not->toContain($reference);
});

/* -------------------------------------------------------------------- actions */

it('computes actions[] correctly at each status for each side', function () {
    [$requesterUser] = xfrRequester();
    [$providerUser, $providerCompany] = xfrProvider();

    $create = $this->actingAs($requesterUser, 'sanctum')
        ->postJson('/api/v1/transformation/requests', xfrCreatePayload($providerCompany))
        ->assertCreated();

    $reference = $create->json('data.reference');

    $requesterView = $this->actingAs($requesterUser, 'sanctum')
        ->getJson("/api/v1/transformation/requests/{$reference}")
        ->assertOk();

    expect(collect($requesterView->json('data.actions'))->pluck('key'))->toContain('cancel');

    $providerView = $this->actingAs($providerUser, 'sanctum')
        ->getJson("/api/v1/transformation/requests/{$reference}")
        ->assertOk();

    $providerActionKeys = collect($providerView->json('data.actions'))->pluck('key');

    expect($providerActionKeys)->toContain('accept')
        ->toContain('decline')
        ->toContain('quote');

    // Provider quotes -> only the requester can act now.
    $this->actingAs($providerUser, 'sanctum')
        ->postJson("/api/v1/transformation/requests/{$reference}/quote", [
            'amount' => 100, 'currency' => 'USD', 'lead_time_days' => 3,
        ])
        ->assertOk();

    $afterQuoteRequester = $this->actingAs($requesterUser, 'sanctum')
        ->getJson("/api/v1/transformation/requests/{$reference}")
        ->assertOk();

    expect(collect($afterQuoteRequester->json('data.actions'))->pluck('key'))
        ->toContain('acceptQuote')
        ->toContain('declineQuote');

    $afterQuoteProvider = $this->actingAs($providerUser, 'sanctum')
        ->getJson("/api/v1/transformation/requests/{$reference}")
        ->assertOk();

    expect($afterQuoteProvider->json('data.actions'))->toBe([]);
});
