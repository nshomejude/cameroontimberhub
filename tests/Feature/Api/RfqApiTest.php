<?php

use App\Mail\RfqVerificationMail;
use App\Models\Rfq;
use App\Models\Species;
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
        ->assertStatus(422)->assertJsonValidationErrors('items', 'error.details');

    $payload = apiRfqPayload();
    unset($payload['destination_country_code']);

    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/rfqs', $payload)
        ->assertStatus(422)->assertJsonValidationErrors('destination_country_code', 'error.details');
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

/* ------------------------------------------------------- species linking */

/**
 * `species_slug` is the handle a client actually holds — /species is keyed by
 * slug and never exposes numeric ids. It used to be dropped on the floor: the
 * item was written with `species_id` null and the API answered 201, so the
 * client believed the catalogue link had been made.
 */
it('resolves species_slug on a line item to the real catalogue species', function () {
    $buyer = User::factory()->create();
    $iroko = Species::factory()->create(['slug' => 'iroko', 'common_name' => 'Iroko']);
    Species::factory()->create(['slug' => 'sapele', 'common_name' => 'Sapele']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [[
                'species_slug' => 'iroko',
                'form' => 'sawn',
                'quantity' => 60,
                'unit' => 'm3',
            ]],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.items.0.species.slug', 'iroko')
        ->assertJsonPath('data.items.0.species.common_name', 'Iroko');

    $item = Rfq::whereReferenceCode($response->json('data.reference'))->firstOrFail()->items()->firstOrFail();

    // The link is real in the database, not merely echoed back.
    expect((int) $item->species_id)->toBe($iroko->id);
});

it('matches the slug case-insensitively and lets species_slug win over a posted species_id', function () {
    $buyer = User::factory()->create();
    $iroko = Species::factory()->create(['slug' => 'iroko']);
    $sapele = Species::factory()->create(['slug' => 'sapele']);

    $reference = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [[
                'species_slug' => 'IROKO',
                'species_id' => $sapele->id,
                'form' => 'sawn',
                'quantity' => 60,
                'unit' => 'm3',
            ]],
        ]))
        ->assertCreated()
        ->json('data.reference');

    expect((int) Rfq::whereReferenceCode($reference)->firstOrFail()->items()->firstOrFail()->species_id)
        ->toBe($iroko->id);
});

it('still accepts a bare species_id', function () {
    $buyer = User::factory()->create();
    $species = Species::factory()->create(['slug' => 'ayous']);

    $reference = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [['species_id' => $species->id, 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']],
        ]))
        ->assertCreated()
        ->json('data.reference');

    expect((int) Rfq::whereReferenceCode($reference)->firstOrFail()->items()->firstOrFail()->species_id)
        ->toBe($species->id);
});

/**
 * The whole point of the fix: a slug that does not resolve is loud. Silently
 * nulling the link was the worst outcome because the client could not tell.
 */
it('422s an unknown species_slug with a mappable field key and writes nothing', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [['species_slug' => 'unobtainium', 'form' => 'sawn', 'quantity' => 5, 'unit' => 'm3']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.species_slug', 'error.details');

    expect(Rfq::count())->toBe(0);
    Mail::assertNothingSent();
});

/** An unpublished species answers exactly as a nonexistent one — no draft-row oracle. */
it('422s an unpublished species_slug just as it does an unknown one', function () {
    $buyer = User::factory()->create();
    Species::factory()->unpublished()->create(['slug' => 'secretwood']);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [['species_slug' => 'secretwood', 'form' => 'sawn', 'quantity' => 5, 'unit' => 'm3']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.species_slug', 'error.details');

    expect(Rfq::count())->toBe(0);
});

it('reports the failing line item by index when only one of several slugs is bad', function () {
    $buyer = User::factory()->create();
    Species::factory()->create(['slug' => 'iroko']);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [
                ['species_slug' => 'iroko', 'form' => 'sawn', 'quantity' => 5, 'unit' => 'm3'],
                ['species_slug' => 'nope', 'form' => 'sawn', 'quantity' => 5, 'unit' => 'm3'],
            ],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.1.species_slug', 'error.details')
        ->assertJsonMissingValidationErrors('items.0.species_slug', 'error.details');
});

/** Free text stays first-class: an RFQ may legitimately name timber the catalogue does not list. */
it('still accepts species_text for a species that is not in the catalogue', function () {
    $buyer = User::factory()->create();

    $reference = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [['species_text' => 'Bubinga (unlisted)', 'form' => 'sawn', 'quantity' => 8, 'unit' => 'm3']],
        ]))
        ->assertCreated()
        ->assertJsonPath('data.items.0.species_text', 'Bubinga (unlisted)')
        ->json('data.reference');

    $item = Rfq::whereReferenceCode($reference)->firstOrFail()->items()->firstOrFail();

    expect($item->species_id)->toBeNull()
        ->and($item->species_text)->toBe('Bubinga (unlisted)');
});

it('422s a line item that names no species at all', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload([
            'items' => [['form' => 'sawn', 'quantity' => 5, 'unit' => 'm3']],
        ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.species_text', 'error.details');
});

/* --------------------------------------------------------- buyer_company */

/**
 * `rfqs.buyer_company` already exists (2026_06_22_120010_create_rfqs_table) and
 * the web wizard collects it on the contact step, so the API persists it rather
 * than rejecting it. It is descriptive, not identifying — unlike buyer_name and
 * buyer_email, which are still taken from the token only.
 */
it('round-trips buyer_company into the RFQ', function () {
    $buyer = User::factory()->create(['name' => 'Claire Buyer', 'email' => 'claire@example.com']);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload(['buyer_company' => 'Northgate Joinery BV']))
        ->assertCreated()
        ->assertJsonPath('data.buyer_company', 'Northgate Joinery BV');

    $rfq = Rfq::whereReferenceCode($response->json('data.reference'))->firstOrFail();

    expect($rfq->buyer_company)->toBe('Northgate Joinery BV')
        // Persisting a company name must not have loosened identity.
        ->and($rfq->buyer_name)->toBe('Claire Buyer')
        ->and($rfq->buyer_email)->toBe('claire@example.com');

    // And it survives a re-read, not just the create response.
    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs/'.$rfq->reference_code)
        ->assertOk()
        ->assertJsonPath('data.buyer_company', 'Northgate Joinery BV');
});

it('accepts an RFQ with no buyer_company and 422s an oversized one', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload())
        ->assertCreated()
        ->assertJsonPath('data.buyer_company', null);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs', apiRfqPayload(['buyer_company' => str_repeat('a', 200)]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('buyer_company', 'error.details');
});

/* ------------------------------------------------ resend verification */

it('exposes the verification state on every RFQ payload, with a resend path', function () {
    $buyer = User::factory()->create(['email_verified_at' => null]);
    $rfq = Rfq::factory()->create(['user_id' => $buyer->id, 'email_verified_at' => null]);

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs/'.$rfq->reference_code)
        ->assertOk()
        ->assertJsonPath('data.verification.required', true)
        ->assertJsonPath('data.verification.verified', false)
        ->assertJsonPath('data.verification.resend_path', '/api/v1/rfqs/'.$rfq->reference_code.'/resend-verification');

    // Also on the list, so a client that reloads can still explain a stalled RFQ.
    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/rfqs')
        ->assertOk()
        ->assertJsonPath('data.0.verification.required', true);
});

it('re-sends the verification email for the buyer own unverified RFQ', function () {
    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->create([
        'user_id' => $buyer->id,
        'buyer_email' => 'claire@example.com',
        'email_verified_at' => null,
    ]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs/'.$rfq->reference_code.'/resend-verification')
        ->assertOk()
        ->assertJsonPath('data.reference', $rfq->reference_code)
        ->assertJsonPath('data.sent', true)
        ->assertJsonPath('data.email_verification_required', true);

    // Addressed to the RFQ's own recorded address, never a caller-supplied one.
    Mail::assertSent(RfqVerificationMail::class, fn ($mail) => $mail->hasTo('claire@example.com'));

    // Resending does NOT verify the RFQ — the gate still needs the signed link.
    expect($rfq->fresh()->email_verified_at)->toBeNull();
});

/** A retry is not a fault: already-verified is a 200 no-op, not a 409. */
it('is a no-op 200 when the RFQ is already verified', function () {
    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->create(['user_id' => $buyer->id, 'email_verified_at' => now()]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/rfqs/'.$rfq->reference_code.'/resend-verification')
        ->assertOk()
        ->assertJsonPath('data.sent', false)
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.email_verification_required', false);

    Mail::assertNothingSent();
});

it('404s a resend for another buyer RFQ rather than confirming it exists', function () {
    $buyer = User::factory()->create();
    $theirs = Rfq::factory()->create(['user_id' => User::factory()->create()->id, 'email_verified_at' => null]);
    $guestRfq = Rfq::factory()->create(['user_id' => null, 'email_verified_at' => null]);

    foreach ([$theirs->reference_code, $guestRfq->reference_code, 'RFQ-2026-ZZZZZ'] as $reference) {
        $this->actingAs($buyer, 'sanctum')
            ->postJson('/api/v1/rfqs/'.$reference.'/resend-verification')
            ->assertNotFound();
    }

    Mail::assertNothingSent();
});

it('requires authentication to resend', function () {
    $rfq = Rfq::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->postJson('/api/v1/rfqs/'.$rfq->reference_code.'/resend-verification')->assertUnauthorized();
});

it('rate-limits resend-verification per RFQ', function () {
    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->create(['user_id' => $buyer->id, 'email_verified_at' => null]);
    $url = '/api/v1/rfqs/'.$rfq->reference_code.'/resend-verification';

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($buyer, 'sanctum')->postJson($url)->assertOk();
    }

    $this->actingAs($buyer, 'sanctum')->postJson($url)->assertStatus(429);
});
