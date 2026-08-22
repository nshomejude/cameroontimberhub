<?php

use App\Mail\RfqVerificationMail;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function apiRfqPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Sapele sawn timber for a joinery contract',
        'buyer_country_code' => 'FR',
        'destination_country_code' => 'FR',
        'shipping_port' => 'Le Havre',
        'incoterm' => 'FOB',
        'notes' => 'We need kiln-dried stock for a joinery contract starting in the spring.',
        'items' => [[
            'species_text' => 'Sapele',
            'form' => 'sawn',
            'grade' => 'FAS',
            'dimensions' => '50mm x 150mm x 3000mm',
            'quantity' => 120,
            'unit' => 'm3',
        ]],
    ], $overrides);
}

/* ------------------------------------------------------------- creation */

it('creates an RFQ through IntakeService, with a reference code and the verification mail', function () {
    $buyer = User::factory()->create(['name' => 'Claire Buyer', 'email' => 'claire@example.com']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload())
        ->assertCreated();

    $reference = $response->json('data.reference');

    expect($reference)->toMatch('/^RFQ-\d{4}-[A-Z0-9]+$/');

    $rfq = Rfq::whereReferenceCode($reference)->firstOrFail();

    // IntakeService's fingerprints: owner backfill, source, one item, the mail.
    expect((int) $rfq->user_id)->toBe($buyer->id)
        ->and($rfq->buyer_email)->toBe('claire@example.com')
        ->and($rfq->source)->toBe('api')
        ->and($rfq->items)->toHaveCount(1);

    Mail::assertSent(RfqVerificationMail::class);
});

/**
 * The gate is enforced, not skipped. `User` does not implement MustVerifyEmail
 * and nothing sets `email_verified_at` today, so a normal API buyer's RFQ is
 * created unverified and the emailed signed link is the only way through —
 * exactly as for a guest on the public wizard.
 */
it('leaves the RFQ unverified when the account email is not verified', function () {
    $buyer = User::factory()->create(['email_verified_at' => null]);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload())
        ->assertCreated()
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('meta.email_verification_required', true);

    expect(Rfq::whereReferenceCode($response->json('data.reference'))->value('email_verified_at'))->toBeNull();

    Mail::assertSent(RfqVerificationMail::class);
});

/** Satisfied — not bypassed — when this platform has already verified this exact address. */
it('marks the RFQ verified when the account owns a verified email', function () {
    $buyer = User::factory()->create(['email' => 'verified@example.com', 'email_verified_at' => now()]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload())
        ->assertCreated()
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('meta.email_verification_required', false);
});

it('takes the buyer identity from the token and ignores any posted identity', function () {
    $buyer = User::factory()->create(['name' => 'Real Buyer', 'email' => 'real@example.com']);

    $reference = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'buyer_email' => 'attacker@example.com',
            'buyer_name' => 'Someone Else',
        ]))
        ->assertCreated()
        ->json('data.reference');

    $rfq = Rfq::whereReferenceCode($reference)->firstOrFail();

    expect($rfq->buyer_email)->toBe('real@example.com')
        ->and($rfq->buyer_name)->toBe('Real Buyer');
});

it('applies the anti-spam honeypot and writes nothing', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload(['website' => 'http://spam.example']))
        ->assertStatus(422);

    expect(Rfq::count())->toBe(0);
    Mail::assertNothingSent();
});

it('422s an RFQ with no items or a missing destination', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload(['items' => []]))
        ->assertStatus(422)->assertJsonValidationErrors('items');

    $payload = apiRfqPayload();
    unset($payload['destination_country_code']);

    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/rfqs', $payload)
        ->assertStatus(422)->assertJsonValidationErrors('destination_country_code');
});

it('throttles RFQ creation', function () {
    $buyer = User::factory()->create();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/rfqs', apiRfqPayload())->assertCreated();
    }

    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/rfqs', apiRfqPayload())->assertStatus(429);
});

/* -------------------------------------------------------------- reading */

it('lists only the buyer own RFQs', function () {
    $buyer = User::factory()->create();
    $other = User::factory()->create();

    $mine = Rfq::factory()->create(['user_id' => $buyer->id, 'title' => 'My own request']);
    $theirs = Rfq::factory()->create(['user_id' => $other->id, 'title' => 'Somebody else request']);

    $response = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs')->assertOk();

    expect(collect($response->json('data'))->pluck('reference')->all())->toBe([$mine->reference_code])
        ->and(json_encode($response->json()))->not->toContain($theirs->reference_code);
});

it('404s another buyer RFQ rather than confirming it exists', function () {
    $buyer = User::factory()->create();
    $theirs = Rfq::factory()->create(['user_id' => User::factory()->create()->id]);
    $guestRfq = Rfq::factory()->create(['user_id' => null]);

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs/'.$theirs->reference_code)->assertNotFound();
    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs/'.$theirs->reference_code.'/quotes')->assertNotFound();

    // An account-free RFQ is nobody's through this API either.
    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs/'.$guestRfq->reference_code)->assertNotFound();

    // A reference that does not exist is indistinguishable from one that does.
    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs/RFQ-2026-ZZZZZ')->assertNotFound();
});

it('never exposes the anti-spam verdict or the submitter IP', function () {
    $buyer = User::factory()->create();

    Rfq::factory()->create([
        'user_id' => $buyer->id,
        'spam_score' => 73,
        'is_spam' => false,
        'ip_address' => '203.0.113.45',
    ]);

    foreach (['/api/v1/rfqs', '/api/v1/rfqs/'.Rfq::first()->reference_code] as $url) {
        $body = json_encode($this->actingAs($buyer, 'sanctum')->getJson($url)->assertOk()->json());

        expect($body)
            ->not->toContain('spam_score')
            ->not->toContain('is_spam')
            ->not->toContain('ip_address')
            ->not->toContain('203.0.113.45')
            ->not->toContain('visibility');
    }
});

it('returns the RFQ detail with its line items', function () {
    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->create(['user_id' => $buyer->id]);
    $rfq->items()->create(['species_text' => 'Iroko', 'form' => 'sawn', 'quantity' => 40, 'unit' => 'm3']);

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs/'.$rfq->reference_code)
        ->assertOk()
        ->assertJsonPath('data.reference', $rfq->reference_code)
        ->assertJsonPath('data.items.0.species_text', 'Iroko')
        ->assertJsonPath('data.quotes_count', 0);
});
