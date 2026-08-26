<?php

use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Filament\Exporter\Resources\Quotes\QuoteResource;
use App\Mail\QuoteSubmittedMail;
use App\Models\Company;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\BuyerRfqAccess;
use App\Services\IntakeService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */

function supplierUserFor(Company $company): User
{
    $user = User::factory()->create();
    $user->companies()->attach($company, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

/** An approved, verified RFQ routed to a freshly created verified company. */
function routedQuoteContext(array $rfqAttributes = []): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create($rfqAttributes);
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    return [$rfq, $company, $routing];
}

/** A submitted quote with one line: 100 m3 @ 185.00 = 18,500.00. */
function submittedQuote(Rfq $rfq, Company $company, float $unitPrice = 185.00, array $overrides = []): Quote
{
    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create(array_merge([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ], $overrides));

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'quantity' => 100,
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(100, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    return $quote;
}

function access(): BuyerRfqAccess
{
    return app(BuyerRfqAccess::class);
}

/* ------------------------------------------------------- security: buyer side */

it('shows a guest buyer their own RFQ responses through a signed link', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->get(access()->responsesUrl($rfq))
        ->assertOk()
        ->assertSee($rfq->reference_code)
        ->assertSee($company->name)
        ->assertSee('18,500.00');
});

it('rejects an unsigned request for the responses screen', function () {
    [$rfq, $company] = routedQuoteContext();
    submittedQuote($rfq, $company);

    $this->get(route('buyer.rfq.responses', ['rfq' => $rfq->getKey()]))->assertForbidden();
});

it('rejects a tampered signature', function () {
    [$rfq, $company] = routedQuoteContext();
    submittedQuote($rfq, $company);

    $url = access()->responsesUrl($rfq);

    $this->get($url.'x')->assertForbidden();
    $this->get(str_replace('signature=', 'signature=deadbeef', $url))->assertForbidden();
});

it('rejects a signed link whose email hash no longer matches', function () {
    [$rfq, $company] = routedQuoteContext();
    submittedQuote($rfq, $company);

    $url = access()->responsesUrl($rfq);

    // The buyer changes address: every outstanding link dies with it.
    $rfq->update(['buyer_email' => 'someone-else@example.test']);

    $this->get($url)->assertForbidden();
});

it('does not let one buyer signed link reach another buyer RFQ', function () {
    [$mine, $companyA] = routedQuoteContext();
    [$theirs, $companyB] = routedQuoteContext();
    submittedQuote($mine, $companyA);
    $secret = submittedQuote($theirs, $companyB, 999.00);

    $mineUrl = access()->responsesUrl($mine);

    // The signature covers the RFQ id, so swapping it invalidates the link...
    $swapped = str_replace('/rfq/'.$mine->getKey().'/', '/rfq/'.$theirs->getKey().'/', $mineUrl);
    $this->get($swapped)->assertForbidden();

    // ...and the legitimate link never leaks the other buyer's figures.
    $this->get($mineUrl)->assertOk()->assertDontSee($secret->reference_code);
});

it('404s a quote id that belongs to a different RFQ', function () {
    [$mine, $companyA] = routedQuoteContext();
    [$theirs, $companyB] = routedQuoteContext();
    $foreign = submittedQuote($theirs, $companyB);

    // Correctly signed for *my* RFQ, but pointing at someone else's quote.
    $url = access()->quoteUrl($mine, $foreign);

    $this->get($url)->assertNotFound();
});

it('never shows a buyer a draft or withdrawn quote', function () {
    [$rfq, $company] = routedQuoteContext();
    $draft = Quote::factory()->create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()]);

    $this->get(access()->responsesUrl($rfq))
        ->assertOk()
        ->assertDontSee($draft->reference_code)
        ->assertSee('No quotes yet');

    $this->get(access()->quoteUrl($rfq, $draft))->assertNotFound();
});

it('lets a registered buyer reach their own RFQ with no signature at all', function () {
    $buyer = User::factory()->create(['email' => 'buyer@acme.test']);
    [$rfq, $company] = routedQuoteContext(['buyer_email' => 'buyer@acme.test', 'user_id' => $buyer->getKey()]);
    submittedQuote($rfq, $company);

    $this->actingAs($buyer)
        ->get(route('buyer.rfq.responses', ['rfq' => $rfq->getKey()]))
        ->assertOk()
        ->assertSee($rfq->reference_code);
});

it('forbids a signed-in user from opening someone else RFQ', function () {
    $intruder = User::factory()->create();
    $owner = User::factory()->create(['email' => 'owner@acme.test']);
    [$rfq, $company] = routedQuoteContext(['buyer_email' => 'owner@acme.test', 'user_id' => $owner->getKey()]);
    submittedQuote($rfq, $company);

    $this->actingAs($intruder)
        ->get(route('buyer.rfq.responses', ['rfq' => $rfq->getKey()]))
        ->assertForbidden();
});

it('binds a new RFQ to a matching account and backfills on registration', function () {
    $existing = User::factory()->create(['email' => 'known@acme.test']);

    $rfq = app(IntakeService::class)->createRfq(
        ['buyer_name' => 'Known', 'buyer_email' => 'known@acme.test'],
        [['species_text' => 'Iroko', 'quantity' => 5, 'unit' => 'm3']],
    );
    expect($rfq->user_id)->toBe($existing->getKey());

    // A guest RFQ from an address that registers later gets adopted.
    $guest = app(IntakeService::class)->createRfq(
        ['buyer_name' => 'Later', 'buyer_email' => 'later@acme.test'],
        [['species_text' => 'Ayous', 'quantity' => 5, 'unit' => 'm3']],
    );
    expect($guest->user_id)->toBeNull();

    $this->post(route('register.store'), [
        'account_type' => 'buyer',
        'name' => 'Later Buyer',
        'email' => 'later@acme.test',
        'password' => 'Str0ng-Passw0rd!',
        'password_confirmation' => 'Str0ng-Passw0rd!',
        'terms' => '1',
    ]);

    expect($guest->fresh()->user_id)->toBe(User::where('email', 'later@acme.test')->value('id'));
});

/* ---------------------------------------------------- security: supplier side */

it('refuses to let a supplier quote an RFQ that was not routed to them', function () {
    [$rfq] = routedQuoteContext();
    $outsider = Company::factory()->publiclyVisible()->create();

    expect(fn () => app(QuoteService::class)->open($rfq, $outsider))
        ->toThrow(RuntimeException::class, 'not routed');
});

it('refuses to let a supplier quote an RFQ that is not approved', function () {
    [$rfq, $company] = routedQuoteContext();
    $rfq->update(['status' => RfqStatus::InReview]);

    expect(fn () => app(QuoteService::class)->open($rfq, $company))
        ->toThrow(RuntimeException::class, 'Only approved RFQs');
});

it('scopes the exporter quote list to the signed-in supplier company', function () {
    [$rfqA, $companyA] = routedQuoteContext();
    [$rfqB, $companyB] = routedQuoteContext();
    $mine = submittedQuote($rfqA, $companyA);
    $theirs = submittedQuote($rfqB, $companyB);

    $this->actingAs(supplierUserFor($companyA));

    $visible = QuoteResource::getEloquentQuery()->pluck('id');

    expect($visible)->toContain($mine->getKey())
        ->and($visible)->not->toContain($theirs->getKey());
});

it('hides the exporter quote list entirely from a user with no company', function () {
    [$rfq, $company] = routedQuoteContext();
    submittedQuote($rfq, $company);

    $this->actingAs(User::factory()->create());

    expect(QuoteResource::getEloquentQuery()->count())->toBe(0);
});

/* ------------------------------------------------------------------- totals */

it('computes line totals, subtotal and total on the server', function () {
    [$rfq, $company, $routing] = routedQuoteContext();

    $quote = app(QuoteService::class)->open($rfq, $company, ['shipping_amount' => 1200.50, 'tax_amount' => 300.25]);
    $quote->items()->create([
        'description' => 'Sawn timber', 'quantity' => 100, 'unit' => 'm3', 'unit_price' => 185.00, 'line_total' => 0,
    ]);
    $quote->items()->create([
        'description' => 'Logs', 'quantity' => 12.5, 'unit' => 'm3', 'unit_price' => 19.99, 'line_total' => 0,
    ]);

    $quote = app(QuoteService::class)->recalculate($quote->fresh());

    expect((string) $quote->subtotal_amount)->toBe('18749.88')
        ->and((string) $quote->total_amount)->toBe('20250.63')
        ->and((string) $quote->items()->orderBy('id')->first()->line_total)->toBe('18500.00');
});

it('ignores a bogus total posted with the line items', function () {
    [$rfq, $company] = routedQuoteContext();

    $quote = app(QuoteService::class)->open($rfq, $company);
    $quote->forceFill(['subtotal_amount' => 1, 'total_amount' => 1])->save();
    $quote->items()->create([
        'description' => 'Sawn timber', 'quantity' => 100, 'unit' => 'm3', 'unit_price' => 185.00,
        // A supplier posting a hand-written line total gets it overwritten.
        'line_total' => 1.00,
    ]);

    $quote = app(QuoteService::class)->recalculate($quote->fresh());

    expect((string) $quote->total_amount)->toBe('18500.00')
        ->and((string) $quote->items()->first()->line_total)->toBe('18500.00');
});

/* ---------------------------------------------------------- state machine */

it('walks a quote from draft through submitted to accepted', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = app(QuoteService::class)->open($rfq, $company);
    $quote->items()->create(['description' => 'Sawn timber', 'quantity' => 10, 'unit' => 'm3', 'unit_price' => 100, 'line_total' => 0]);

    $service = app(QuoteService::class);
    $quote = $service->submit($quote->fresh());
    expect($quote->status)->toBe(QuoteStatus::Submitted)
        ->and($quote->submitted_at)->not->toBeNull()
        ->and((string) $quote->total_amount)->toBe('1000.00');

    $quote = $service->markViewed($quote);
    expect($quote->status)->toBe(QuoteStatus::Viewed);

    $quote = $service->accept($quote);
    expect($quote->status)->toBe(QuoteStatus::Accepted)
        ->and($quote->decided_at)->not->toBeNull();
});

it('throws on an illegal transition', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    app(QuoteService::class)->accept($quote);

    expect(fn () => app(QuoteService::class)->decline($quote->fresh(), 'too late'))
        ->toThrow(RuntimeException::class, 'Illegal quote transition');
});

it('refuses to submit a quote with no line items', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = app(QuoteService::class)->open($rfq, $company);

    expect(fn () => app(QuoteService::class)->submit($quote))
        ->toThrow(RuntimeException::class, 'at least one line item');
});

it('declines every other open quote atomically when one is accepted', function () {
    [$rfq, $companyA] = routedQuoteContext();
    $companyB = Company::factory()->publiclyVisible()->create();
    $companyC = Company::factory()->publiclyVisible()->create();

    $winner = submittedQuote($rfq, $companyA, 150.00);
    $loserB = submittedQuote($rfq, $companyB, 180.00);
    $loserC = submittedQuote($rfq, $companyC, 210.00);

    app(QuoteService::class)->accept($winner);

    expect($winner->fresh()->status)->toBe(QuoteStatus::Accepted)
        ->and($loserB->fresh()->status)->toBe(QuoteStatus::Declined)
        ->and($loserC->fresh()->status)->toBe(QuoteStatus::Declined)
        ->and($loserB->fresh()->decline_reason)->toContain('Another quote was accepted')
        ->and($rfq->fresh()->status)->toBe(RfqStatus::Closed);
});

it('cannot accept an expired quote', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company, 185.00, ['valid_until' => now()->subDay()->toDateString()]);

    expect(fn () => app(QuoteService::class)->accept($quote))
        ->toThrow(RuntimeException::class, 'expired');

    expect($quote->fresh()->status)->toBe(QuoteStatus::Submitted);
});

it('expires lapsed quotes and leaves live ones alone', function () {
    [$rfq, $companyA] = routedQuoteContext();
    $companyB = Company::factory()->publiclyVisible()->create();

    $lapsed = submittedQuote($rfq, $companyA, 100.00, ['valid_until' => now()->subDays(2)->toDateString()]);
    $live = submittedQuote($rfq, $companyB, 100.00, ['valid_until' => now()->addDays(5)->toDateString()]);

    expect(app(QuoteService::class)->expireLapsed())->toBe(1)
        ->and($lapsed->fresh()->status)->toBe(QuoteStatus::Expired)
        ->and($live->fresh()->status)->toBe(QuoteStatus::Submitted);
});

it('allows only one active quote per supplier per RFQ', function () {
    [$rfq, $company] = routedQuoteContext();

    app(QuoteService::class)->open($rfq, $company);

    expect(fn () => app(QuoteService::class)->open($rfq, $company))
        ->toThrow(RuntimeException::class, 'already has a quote');

    // The database enforces it too, independently of the service guard.
    expect(fn () => Quote::factory()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(),
    ]))->toThrow(QueryException::class);
});

it('frees the slot again after a withdrawal', function () {
    [$rfq, $company] = routedQuoteContext();

    $first = app(QuoteService::class)->open($rfq, $company);
    app(QuoteService::class)->withdraw($first);

    $second = app(QuoteService::class)->open($rfq, $company);

    expect($second->getKey())->not->toBe($first->getKey())
        ->and($first->fresh()->status)->toBe(QuoteStatus::Withdrawn);
});

it('writes an activity log entry for every transition', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    Activity::query()->delete();

    app(QuoteService::class)->decline($quote, 'Price above budget');

    $log = Activity::where('log_name', 'quote')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->event)->toBe('status_changed')
        ->and($log->properties['to'])->toBe('declined')
        ->and($log->properties['reason'])->toBe('Price above budget')
        ->and($log->subject_id)->toBe($quote->getKey());
});

/* ---------------------------------------------------------- buyer screens */

it('renders the responses screen with real data, noindex and a sort control', function () {
    [$rfq, $companyA] = routedQuoteContext(['title' => 'Teak lumber supply for Q3']);
    $companyB = Company::factory()->publiclyVisible()->create();

    submittedQuote($rfq, $companyA, 185.00);
    submittedQuote($rfq, $companyB, 150.00);

    $this->get(access()->responsesUrl($rfq))
        ->assertOk()
        ->assertSee('Teak lumber supply for Q3')
        ->assertSee('noindex', false)
        ->assertSee($companyA->name)
        ->assertSee($companyB->name)
        ->assertSee('USD 15,000.00')
        ->assertSee('USD 18,500.00')
        ->assertSee('Lowest total')
        ->assertSee('Sort by');
});

it('shows an honest empty state when nothing has been quoted', function () {
    [$rfq] = routedQuoteContext();

    $this->get(access()->responsesUrl($rfq))
        ->assertOk()
        ->assertSee('No quotes yet')
        ->assertSee($rfq->buyer_email)
        ->assertDontSee('Lowest total');
});

it('renders the quote detail screen with the full breakdown and both actions', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->get(access()->quoteUrl($rfq, $quote))
        ->assertOk()
        ->assertSee('noindex', false)
        ->assertSee($quote->reference_code)
        ->assertSee($company->name)
        ->assertSee('Products quoted')
        ->assertSee('18,500.00')
        ->assertSee('30% advance, 70% on delivery')
        ->assertSee('Accept this quote')
        ->assertSee('Decline this quote')
        ->assertSee('name="_token"', false);
});

it('marks a quote viewed when the buyer opens it', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->get(access()->quoteUrl($rfq, $quote))->assertOk();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Viewed)
        ->and($quote->fresh()->viewed_at)->not->toBeNull();
});

it('shows a decided quote without the accept and decline forms', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);
    app(QuoteService::class)->accept($quote);

    $this->get(access()->quoteUrl($rfq, $quote->fresh()))
        ->assertOk()
        ->assertSee('You accepted this quote')
        ->assertDontSee('Accept this quote');
});

/* ------------------------------------------------------- accept / decline */

it('never exposes accept or decline over GET', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->get(access()->acceptUrl($rfq, $quote))->assertMethodNotAllowed();
    $this->get(access()->declineUrl($rfq, $quote))->assertMethodNotAllowed();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Submitted);
});

it('accepts a quote by POST with an explicit confirmation', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->post(access()->acceptUrl($rfq, $quote), ['confirm' => '1'])
        ->assertRedirect();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Accepted);
});

it('refuses to accept without the confirmation checkbox', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->post(access()->acceptUrl($rfq, $quote), [])
        ->assertSessionHasErrors('confirm');

    expect($quote->fresh()->status)->toBe(QuoteStatus::Submitted);
});

it('requires a reason to decline', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->post(access()->declineUrl($rfq, $quote), [])->assertSessionHasErrors('reason');
    $this->post(access()->declineUrl($rfq, $quote), ['reason' => 'no'])->assertSessionHasErrors('reason');

    expect($quote->fresh()->status)->toBe(QuoteStatus::Submitted);

    $this->post(access()->declineUrl($rfq, $quote), ['reason' => 'Price above our budget'])
        ->assertRedirect();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Declined)
        ->and($quote->fresh()->decline_reason)->toBe('Price above our budget');
});

it('refuses an unsigned accept POST', function () {
    [$rfq, $company] = routedQuoteContext();
    $quote = submittedQuote($rfq, $company);

    $this->post(route('buyer.rfq.quote.accept', ['rfq' => $rfq->getKey(), 'quote' => $quote->getKey()]), ['confirm' => '1'])
        ->assertForbidden();

    expect($quote->fresh()->status)->toBe(QuoteStatus::Submitted);
});

/* -------------------------------------------------------------------- mail */

it('emails the buyer when a supplier submits a quote', function () {
    [$rfq, $company] = routedQuoteContext();

    $quote = app(QuoteService::class)->open($rfq, $company);
    $quote->items()->create(['description' => 'Sawn timber', 'quantity' => 10, 'unit' => 'm3', 'unit_price' => 100, 'line_total' => 0]);

    app(QuoteService::class)->submit($quote->fresh());

    Mail::assertQueued(QuoteSubmittedMail::class, fn (QuoteSubmittedMail $mail) => $mail->hasTo($rfq->buyer_email));
});

it('moves the routing row to responded on submit', function () {
    [$rfq, $company, $routing] = routedQuoteContext();

    $quote = app(QuoteService::class)->open($rfq, $company);
    $quote->items()->create(['description' => 'Sawn timber', 'quantity' => 10, 'unit' => 'm3', 'unit_price' => 100, 'line_total' => 0]);
    app(QuoteService::class)->submit($quote->fresh());

    expect($routing->fresh()->status->value)->toBe('responded');
});
