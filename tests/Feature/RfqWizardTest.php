<?php

use App\Enums\ProductStatus;
use App\Enums\RfqStatus;
use App\Mail\RfqVerificationMail;
use App\Models\Company;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\Species;
use App\Models\User;
use App\Services\IntakeService;
use App\Services\RfqList;
use App\Services\RfqWizard;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->species = Species::factory()->create(['common_name' => 'Iroko', 'slug' => 'iroko-wiz', 'is_published' => true]);
});

/** Bank every step so the wizard reaches `review` in a valid state. */
function completeWizard(array $overrides = []): array
{
    $species = test()->species;

    $steps = array_replace_recursive([
        'details' => ['title' => 'Sawn Iroko for a decking project'],
        'products' => ['items' => [[
            'species_id' => $species->id,
            'form' => 'sawn',
            'quantity' => '30',
            'unit' => 'm3',
        ]]],
        'delivery' => [
            'destination_country_code' => 'NL',
            'notes' => 'Kiln dried to 12 percent, FAS grade, please quote CIF Rotterdam.',
        ],
        'contact' => [
            'buyer_name' => 'Bob Buyer',
            'buyer_email' => 'bob@acme.test',
            'buyer_country_code' => 'NL',
            'consent' => '1',
        ],
    ], $overrides);

    foreach ($steps as $slug => $payload) {
        test()->post("/request-quote/step/{$slug}", $payload)->assertRedirect();
    }

    return $steps;
}

it('renders the wizard entry point and gates unreachable steps', function () {
    $this->get('/request-quote')->assertOk();
    $this->get('/request-quote/step/details')->assertOk();

    // Deep-linking past the first incomplete step bounces back rather than
    // rendering a summary of nothing.
    $this->get('/request-quote/step/review')->assertRedirect('/request-quote/step/details');

    $this->get('/request-quote/step/not-a-step')->assertNotFound();
});

it('validates each step independently and preserves the entered input', function () {
    $this->post('/request-quote/step/details', ['title' => 'no'])
        ->assertSessionHasErrors('title')
        ->assertRedirect();

    // Nothing was banked, so the wizard has not advanced.
    $this->get('/request-quote/step/products')->assertRedirect('/request-quote/step/details');

    $this->post('/request-quote/step/details', ['title' => 'A perfectly valid RFQ title'])
        ->assertSessionHasNoErrors();

    $this->get('/request-quote/step/products')->assertOk();
});

it('lets the visitor step backwards without tripping validation', function () {
    $this->post('/request-quote/step/details', ['title' => 'A perfectly valid RFQ title']);

    // Going back from an empty products step must not block on its errors.
    $this->post('/request-quote/step/products', ['direction' => 'back'])
        ->assertRedirect('/request-quote/step/details')
        ->assertSessionHasNoErrors();
});

it('keeps banked state across a full round trip', function () {
    completeWizard();

    $this->get('/request-quote/step/review')->assertOk()
        ->assertSee('Sawn Iroko for a decking project')
        ->assertSee('bob@acme.test');

    // Revisiting an earlier step still shows what was entered.
    $this->get('/request-quote/step/contact')->assertOk()->assertSee('Bob Buyer');
});

it('creates exactly one rfq with items, unverified, and mails the signed link', function () {
    completeWizard();

    $this->post('/request-quote')->assertRedirect('/request-quote/thanks');

    expect(Rfq::count())->toBe(1);

    $rfq = Rfq::with('items')->first();

    expect($rfq->items)->toHaveCount(1)
        ->and($rfq->reference_code)->not->toBeEmpty()
        ->and($rfq->status)->toBe(RfqStatus::New)
        // The verification gate: not actionable until the buyer confirms.
        ->and($rfq->email_verified_at)->toBeNull()
        ->and($rfq->buyer_email)->toBe('bob@acme.test');

    Mail::assertSent(RfqVerificationMail::class);
});

it('tells the buyer nothing has been sent to exporters yet', function () {
    completeWizard();
    $this->post('/request-quote');

    $this->get('/request-quote/thanks')
        ->assertOk()
        ->assertSee('has not been sent to any exporter yet', escape: false)
        ->assertSee('noindex', escape: false);
});

it('verifies through the signed link and is idempotent', function () {
    completeWizard();
    $this->post('/request-quote');

    $rfq = Rfq::first();
    $url = app(IntakeService::class)->rfqVerifyUrl($rfq);

    $this->get($url)->assertOk();
    $verifiedAt = $rfq->fresh()->email_verified_at;
    expect($verifiedAt)->not->toBeNull();

    // Opening the link again must not move the timestamp or duplicate work.
    $this->get($url)->assertOk();
    expect($rfq->fresh()->email_verified_at->eq($verifiedAt))->toBeTrue();
});

it('rejects a tampered verification hash', function () {
    completeWizard();
    $this->post('/request-quote');

    $rfq = Rfq::first();
    $this->get(route('rfq.verify', ['rfq' => $rfq->id, 'h' => 'wrong']))->assertForbidden();
});

it('silently swallows a honeypot submission without writing a row', function () {
    completeWizard();

    // `website` is the hidden trap field; a bot filling it gets a neutral
    // success page so it cannot tell it was caught.
    $this->post('/request-quote', ['website' => 'http://spam.test'])
        ->assertRedirect('/request-quote/thanks');

    expect(Rfq::count())->toBe(0);
    Mail::assertNothingSent();
});

it('rejects a submission completed faster than a human could type it', function () {
    completeWizard();

    $this->post('/request-quote', ['form_rendered_at' => now()->timestamp])
        ->assertRedirect('/request-quote/thanks');

    expect(Rfq::count())->toBe(0);
});

it('clears the wizard session after a successful submit', function () {
    completeWizard();
    $this->post('/request-quote');

    expect(session(RfqWizard::KEY))->toBeNull();

    // A fresh visit starts clean rather than resubmitting the same basket.
    $this->get('/request-quote/step/review')->assertRedirect('/request-quote/step/details');
});

it('pre-fills a signed-in buyer without requiring an account', function () {
    $user = User::factory()->create(['name' => 'Signed In Buyer', 'email' => 'signed@acme.test']);

    // The entry point seeds the session; the details step renders first, so the
    // pre-filled address shows once the visitor reaches the contact step.
    $this->actingAs($user)->get('/request-quote')->assertOk();

    expect(session(RfqWizard::KEY)['contact']['buyer_email'] ?? null)->toBe('signed@acme.test')
        // Consent is never pre-ticked — it must be a fresh act each time.
        ->and(session(RfqWizard::KEY)['contact']['consent'] ?? null)->toBeNull();

    // And login is never required: a guest can still open the wizard.
    auth()->logout();
    $this->get('/request-quote')->assertOk();
});

it('pre-fills the basket from the session rfq shortlist and clears it on submit', function () {
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create([
        'company_id' => $company->id,
        'species_id' => $this->species->id,
        'name' => 'Iroko Sawn Timber KD 50mm',
        'status' => ProductStatus::Active,
    ]);

    $this->post(route('rfq-list.store', $product->slug));
    $this->get('/request-quote')->assertOk();

    expect(app(RfqWizard::class)->items())->toHaveCount(1);

    completeWizard();
    $this->post('/request-quote');

    expect(app(RfqList::class)->products())->toBeEmpty();
});

it('reports a real count of matching suppliers, not a promise', function () {
    completeWizard();

    // No supplier lists this species yet.
    $this->get('/request-quote/step/review')->assertOk()->assertSee('0');

    $company = Company::factory()->publiclyVisible()->create();
    $company->species()->syncWithoutDetaching([$this->species->id]);

    expect(app(RfqWizard::class)->matchingSupplierCount())->toBe(1);
});

it('still accepts a single full-payload post for backwards compatibility', function () {
    $this->post('/request-quote', [
        'title' => 'Legacy single page submission',
        'species_id' => $this->species->id,
        'form' => 'sawn',
        'quantity' => '25',
        'unit' => 'm3',
        'destination_country_code' => 'BE',
        'notes' => 'Legacy flat payload should still validate and submit fine.',
        'buyer_name' => 'Legacy Buyer',
        'buyer_email' => 'legacy@acme.test',
        'buyer_country_code' => 'BE',
        'consent' => '1',
    ])->assertRedirect('/request-quote/thanks');

    expect(Rfq::count())->toBe(1)
        ->and(Rfq::first()->items)->toHaveCount(1);
});
