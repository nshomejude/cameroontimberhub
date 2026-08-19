<?php

use App\Enums\CompanyUserRole;
use App\Enums\CounterOfferStatus;
use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Livewire\Messaging\Thread;
use App\Models\Company;
use App\Models\ContractAcceptance;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Models\QuoteItem;
use App\Models\Receipt;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    RateLimiter::clear('chat-rfq:1');
});

/* ------------------------------------------------------------------ helpers */
/* Prefixed `cc` so they never collide with MessagingTest / QuoteTest / OrderTest
   helpers — Pest loads every feature file into the same process. */

function ccCommerce(): ChatCommerceService
{
    return app(ChatCommerceService::class);
}

/**
 * A whole negotiable situation: a registered buyer, a verified company with one
 * staff member, an approved RFQ routed to that company, a submitted single-line
 * quote, an open conversation, and the quotation card already in the thread.
 *
 * @return array{0: Conversation, 1: User, 2: Company, 3: User, 4: Quote, 5: Rfq}
 */
function ccScene(array $quoteOverrides = []): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $staff = User::factory()->create(['email' => 'ccstaff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    $buyer = User::factory()->create([
        'email' => 'ccbuyer'.uniqid().'@example.com',
        'email_verified_at' => now(),
    ]);

    $rfq = Rfq::factory()->approved()->create([
        'user_id' => $buyer->getKey(),
        'buyer_email' => $buyer->email,
    ]);
    $rfqItem = $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create(array_merge([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
        'currency' => 'USD',
        'incoterm' => 'CIF',
        'lead_time_days' => 10,
        'validity_days' => 15,
        'valid_until' => now()->addDays(15)->toDateString(),
        'payment_terms' => '50% advance, 50% before shipment',
    ], $quoteOverrides));

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'rfq_item_id' => $rfqItem->getKey(),
        'description' => 'Premium Sapele Lumber (KD)',
        'quantity' => 50,
        'unit' => 'm3',
        'unit_price' => 620.00,
        'line_total' => Quote::lineTotal(50, 620.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    $conversation = app(MessagingService::class)->start($buyer, $company);

    ccCommerce()->issueQuotation($conversation, $quote->refresh(), $staff);

    return [$conversation->refresh(), $buyer, $company, $staff, $quote->refresh(), $rfq->refresh()];
}

/** The quotation card that sits in a thread for a given quote. */
function ccCard(Conversation $conversation, Quote $quote): Message
{
    return $conversation->messages()
        ->where('type', MessageType::Quotation->value)
        ->where('related_id', $quote->getKey())
        ->firstOrFail();
}

/** Valid payload for the in-thread RFQ composer. */
function ccRfqPayload(array $overrides = []): array
{
    return array_merge([
        'species_text' => 'Sapele',
        'form' => 'sawn',
        'grade' => 'Premium',
        'dimensions' => '50mm x 200mm x 2.4m',
        'moisture_content' => 'Kiln Dried',
        'quantity' => 50,
        'unit' => 'm3',
        'incoterm' => 'CIF',
        'shipping_port' => 'Douala Port',
        'notes' => 'Standard export packing please.',
    ], $overrides);
}

/* ============================================================ RFQ FROM CHAT */

it('creates a chat RFQ through the real intake path and posts a card', function () {
    [$conversation, $buyer] = ccScene();

    $rfq = ccCommerce()->createRfqFromChat($conversation, $buyer, ccRfqPayload());

    // The real write path: IntakeService generated the reference, stamped the
    // source and the IP, and evaluated risk — none of which a parallel path
    // would have done.
    expect($rfq->reference_code)->not->toBeEmpty()
        ->and($rfq->source)->toBe('chat')
        ->and($rfq->status)->toBe(RfqStatus::New)
        ->and($rfq->items)->toHaveCount(1)
        ->and($rfq->items->first()->species_text)->toBe('Sapele')
        ->and($rfq->user_id)->toBe($buyer->getKey());

    $card = $conversation->messages()->where('type', MessageType::RfqReference->value)->first();

    expect($card)->not->toBeNull()
        ->and($card->payloadValue('reference_code'))->toBe($rfq->reference_code)
        ->and($card->related_id)->toBe($rfq->getKey());

    // The thread records the RFQ as context only when it had none. Here it
    // already had one (from the quotation that opened the scene), and existing
    // context is never overwritten — the same "only add what is missing" rule
    // MessagingService::start() follows.
    expect($conversation->refresh()->rfq_id)->not->toBe($rfq->getKey());

    $bare = app(MessagingService::class)->start($buyer, Company::factory()->publiclyVisible()->create());
    $second = ccCommerce()->createRfqFromChat($bare, $buyer, ccRfqPayload());

    expect($bare->refresh()->rfq_id)->toBe($second->getKey());
});

it('honours the anti-spam honeypot on the chat RFQ composer', function () {
    [$conversation, $buyer] = ccScene();

    $before = Rfq::count();

    expect(fn () => ccCommerce()->createRfqFromChat($conversation, $buyer, ccRfqPayload(['website' => 'https://spam.example'])))
        ->toThrow(RuntimeException::class);

    expect(Rfq::count())->toBe($before);
});

it('satisfies the verification gate for a buyer whose own address is already verified', function () {
    [$conversation, $buyer] = ccScene();

    expect($buyer->email_verified_at)->not->toBeNull();

    $rfq = ccCommerce()->createRfqFromChat($conversation, $buyer, ccRfqPayload());

    // Not skipped — satisfied. The platform already proved this exact mailbox.
    expect($rfq->isVerified())->toBeTrue()
        ->and($rfq->buyer_email)->toBe($buyer->email);
});

it('still enforces the verification gate when the buyer account is unverified', function () {
    [$conversation, $buyer] = ccScene();

    $buyer->forceFill(['email_verified_at' => null])->save();

    $rfq = ccCommerce()->createRfqFromChat($conversation, $buyer->refresh(), ccRfqPayload());

    // The signed email link remains the only way through, exactly as on the
    // public wizard. Being signed in is not, on its own, proof of the mailbox.
    expect($rfq->isVerified())->toBeFalse();
});

it('refuses a chat RFQ from the supplier side', function () {
    [$conversation, , , $staff] = ccScene();

    expect(fn () => ccCommerce()->createRfqFromChat($conversation, $staff, ccRfqPayload()))
        ->toThrow(HttpException::class);
});

/* ========================================================== QUOTATION CARD */

it('renders snapshot money but live status on the quotation card', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    $card = ccCard($conversation, $quote);
    $payloadBefore = $card->payload;

    $this->actingAs($buyer)->get(route('account.messages.show', $conversation))
        ->assertOk()
        ->assertSee('USD 31,000.00')
        ->assertSee('Submitted');

    // Move the LIVE record underneath the card.
    app(QuoteService::class)->expire($quote);

    $this->actingAs($buyer)->get(route('account.messages.show', $conversation))
        ->assertOk()
        // Snapshot money is unchanged...
        ->assertSee('USD 31,000.00')
        // ...but the status the card shows has followed the quote.
        ->assertSee('Expired')
        ->assertDontSee('Accept quotation');

    // And crucially, the message row itself was never rewritten.
    $card->refresh();
    expect($card->payload)->toBe($payloadBefore)
        ->and($card->updated_at->equalTo($card->created_at))->toBeTrue();
});

it('shows a revised predecessor as revised rather than withdrawn', function () {
    [$conversation, $buyer, , $staff, $quote] = ccScene();

    $offer = ccCommerce()->counter($conversation, $quote, $buyer, ['unit_price' => 570]);
    ccCommerce()->respondToCounter($offer, $staff, 'accept');

    expect($quote->refresh()->status)->toBe(QuoteStatus::Withdrawn)
        ->and($quote->isSuperseded())->toBeTrue()
        ->and($quote->threadStatusLabel())->toBe('Revised');
});

it('escapes hostile text in every new card', function () {
    [$conversation, $buyer, , $staff, $quote] = ccScene();

    $xss = '<script>alert("xss")</script>';

    // ... in the RFQ card (buyer-typed species and notes)
    ccCommerce()->createRfqFromChat($conversation, $buyer, ccRfqPayload([
        'species_text' => $xss,
        'notes' => $xss,
    ]));

    // ... in the counter-offer card (either party's free-text note)
    ccCommerce()->counter($conversation, $quote, $buyer, ['unit_price' => 570, 'note' => $xss]);

    $response = $this->actingAs($buyer)->get(route('account.messages.show', $conversation));

    $response->assertOk()
        ->assertDontSee('<script>alert', false)
        ->assertSee('&lt;script&gt;', false);
});

/* ================================================= ACCEPTANCE FROM THE CHAT */

it('runs the full accept path when the buyer accepts from the thread', function () {
    [$conversation, $buyer, $company, $staff, $quote, $rfq] = ccScene();

    // A second supplier with an open quote on the same RFQ, so the sibling
    // decline is actually observable.
    $rival = Company::factory()->publiclyVisible()->create();
    $rivalRouting = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $rival->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);
    $rivalQuote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $rival->getKey(), 'rfq_company_id' => $rivalRouting->getKey(),
    ]);
    QuoteItem::factory()->create(['quote_id' => $rivalQuote->getKey(), 'quantity' => 50, 'unit_price' => 700]);
    $rivalQuote->load('items')->recalculateTotals()->save();

    $acceptance = ccCommerce()->acceptQuotation($conversation, $quote, $buyer);

    // QuoteService::accept() ran, with everything it guarantees.
    expect($quote->refresh()->status)->toBe(QuoteStatus::Accepted)
        ->and($rivalQuote->refresh()->status)->toBe(QuoteStatus::Declined)
        ->and($rfq->refresh()->status)->toBe(RfqStatus::Closed);

    $order = Order::where('quote_id', $quote->getKey())->first();
    expect($order)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::Awarded)
        ->and(Receipt::where('order_id', $order->getKey())->exists())->toBeTrue();

    // And the thread gained both the acceptance record and the order card.
    expect($conversation->messages()->where('type', MessageType::ContractAcceptance->value)->exists())->toBeTrue()
        ->and($conversation->messages()->where('type', MessageType::OrderReference->value)->where('related_id', $order->getKey())->exists())->toBeTrue()
        ->and($conversation->refresh()->order_id)->toBe($order->getKey())
        ->and($acceptance->message_id)->not->toBeNull();
});

it('records actor, time, ip, user agent and a matching terms hash', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    $card = ccCard($conversation, $quote);

    $this->actingAs($buyer)
        ->withHeaders(['User-Agent' => 'PestBrowser/1.0'])
        ->post(route('chat.quote.accept', [$conversation, $quote]))
        ->assertRedirect(route('account.messages.show', $conversation));

    $acceptance = ContractAcceptance::where('quote_id', $quote->getKey())->firstOrFail();

    expect($acceptance->accepted_by_user_id)->toBe($buyer->getKey())
        ->and($acceptance->accepted_by_name)->toBe($buyer->name)
        ->and($acceptance->accepted_at)->not->toBeNull()
        ->and($acceptance->ip_address)->not->toBeEmpty()
        ->and($acceptance->user_agent)->toBe('PestBrowser/1.0')
        ->and($acceptance->terms_hash)->toHaveLength(64)
        // Re-derivable: the stored hash is a hash of the stored terms.
        ->and($acceptance->matchesRecordedTerms())->toBeTrue();

    // The hash covers the SAME figures the card rendered, not a lookalike
    // assembled separately at acceptance time.
    expect($acceptance->terms_hash)->toBe(ContractAcceptance::hashTerms($card->payload))
        ->and($acceptance->terms['total_amount'])->toBe($card->payloadValue('total_amount'))
        ->and($acceptance->terms['reference_code'])->toBe($card->payloadValue('reference_code'));
});

it('states the acceptance factually and never claims a signature', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    ccCommerce()->acceptQuotation($conversation, $quote, $buyer);

    $response = $this->actingAs($buyer)->get(route('account.messages.show', $conversation))->assertOk();

    $response->assertSee('Accepted by '.$buyer->name)
        ->assertSee('It is not an electronic signature');

    foreach (['e-signature', 'digitally signed', 'legally binding', 'certified signature', 'notarised'] as $forbidden) {
        expect(stripos($response->getContent(), $forbidden))->toBeFalse();
    }
});

/* ----------------------------------------------------------- action bounds */

it('refuses to let the supplier accept a quotation', function () {
    [$conversation, , , $staff, $quote] = ccScene();

    $this->actingAs($staff)
        ->post(route('chat.quote.accept', [$conversation, $quote]))
        ->assertForbidden();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Submitted)
        ->and(ContractAcceptance::count())->toBe(0);
});

it('refuses to let the buyer withdraw a quotation', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    $this->actingAs($buyer)
        ->post(route('chat.quote.withdraw', [$conversation, $quote]))
        ->assertForbidden();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Submitted);
});

it('lets the supplier withdraw their own quotation', function () {
    [$conversation, , , $staff, $quote] = ccScene();

    $this->actingAs($staff)
        ->post(route('chat.quote.withdraw', [$conversation, $quote]))
        ->assertRedirect();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Withdrawn);
});

it('shows a non-participant nothing and leaks no identifiers', function () {
    [$conversation, , , , $quote] = ccScene();

    $stranger = User::factory()->create(['email' => 'ccstranger'.uniqid().'@example.com']);

    // Reading the thread: 404, not 403 — the id must not be confirmed.
    $this->actingAs($stranger)
        ->get(route('account.messages.show', $conversation))
        ->assertNotFound();

    foreach ([
        route('chat.quote.accept', [$conversation, $quote]),
        route('chat.quote.withdraw', [$conversation, $quote]),
        route('chat.quote.counter', [$conversation, $quote]),
        route('chat.rfq', $conversation),
    ] as $url) {
        $response = $this->actingAs($stranger)->post($url, ['unit_price' => 1, 'species_text' => 'x', 'quantity' => 1, 'unit' => 'm3']);

        $response->assertNotFound();
        expect($response->getContent())->not->toContain($quote->reference_code);
    }

    expect($quote->refresh()->status)->toBe(QuoteStatus::Submitted);
});

it('does not expose any state change on a GET', function () {
    foreach (['chat.quote.accept', 'chat.quote.decline', 'chat.quote.withdraw', 'chat.quote.counter'] as $name) {
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->getName() === $name);

        expect($route->methods())->toContain('POST')
            ->and($route->methods())->not->toContain('GET');
    }
});

/* --------------------------------------------------------- settled quotes */

it('refuses to accept an already-settled quote', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    ccCommerce()->acceptQuotation($conversation, $quote, $buyer);

    // The application guard.
    expect(fn () => ccCommerce()->acceptQuotation($conversation, $quote->refresh(), $buyer))
        ->toThrow(RuntimeException::class);

    expect(ContractAcceptance::where('quote_id', $quote->getKey())->count())->toBe(1)
        ->and(Order::where('quote_id', $quote->getKey())->count())->toBe(1);
});

/* The two DB-level guarantees get a test each. Postgres aborts the surrounding
   transaction on the first deliberate violation, so asserting two in one test
   would produce a "current transaction is aborted" error in place of the second
   real assertion. */

it('cannot record a second acceptance for the same quote, with the guard bypassed', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    ccCommerce()->acceptQuotation($conversation, $quote, $buyer);

    expect(fn () => DB::table('contract_acceptances')->insert([
        'quote_id' => $quote->getKey(),
        'accepted_by_user_id' => $buyer->getKey(),
        'accepted_by_name' => $buyer->name,
        'accepted_by_email' => $buyer->email,
        'party' => 'buyer',
        'accepted_at' => now(),
        'terms_hash' => str_repeat('a', 64),
        'terms' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('cannot have two accepted quotes on one RFQ, with the guard bypassed', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    // Created before the violation, which aborts the test transaction.
    $other = Company::factory()->publiclyVisible()->create();

    ccCommerce()->acceptQuotation($conversation, $quote, $buyer);

    // The award is exclusive at the storage layer, not only inside
    // QuoteService::accept().
    expect(fn () => DB::table('quotes')->insert([
        'rfq_id' => $quote->rfq_id,
        'company_id' => $other->getKey(),
        'reference_code' => 'QTE-DUP-'.uniqid(),
        'status' => 'accepted',
        'currency' => 'USD',
        'subtotal_amount' => 1,
        'total_amount' => 1,
        'revision' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses to accept an expired quote', function () {
    [$conversation, $buyer, , , $quote] = ccScene([
        'valid_until' => now()->subDay()->toDateString(),
    ]);

    expect(fn () => ccCommerce()->acceptQuotation($conversation, $quote, $buyer))
        ->toThrow(RuntimeException::class);

    expect(Order::count())->toBe(0);
});

/* ============================================================= NEGOTIATION */

it('lets either side counter and only the counterparty respond', function () {
    [$conversation, $buyer, , $staff, $quote] = ccScene();

    $offer = ccCommerce()->counter($conversation, $quote, $buyer, [
        'unit_price' => 570,
        'note' => 'We can proceed at 570 per m3.',
    ]);

    expect($offer->party)->toBe(QuoteCounterOffer::PARTY_BUYER)
        ->and($offer->status)->toBe(CounterOfferStatus::Pending)
        // Total is derived here, never posted.
        ->and((string) $offer->total_amount)->toBe('28500.00')
        ->and($offer->awaitingParty())->toBe(QuoteCounterOffer::PARTY_SUPPLIER);

    // Self-acceptance is impossible: the proposer is never the awaiting party.
    expect(fn () => ccCommerce()->respondToCounter($offer, $buyer, 'accept'))
        ->toThrow(HttpException::class);

    expect($offer->refresh()->status)->toBe(CounterOfferStatus::Pending);

    // The counterparty can.
    ccCommerce()->respondToCounter($offer, $staff, 'decline');

    expect($offer->refresh()->status)->toBe(CounterOfferStatus::Declined)
        ->and($offer->responded_by_user_id)->toBe($staff->getKey());
});

it('produces a revised quote when a counter-offer is agreed', function () {
    [$conversation, $buyer, $company, $staff, $quote] = ccScene();

    $offer = ccCommerce()->counter($conversation, $quote, $buyer, [
        'unit_price' => 590,
        'quantity' => 100,
        'payment_terms' => '50% advance, 50% before shipment',
    ]);

    ccCommerce()->respondToCounter($offer, $staff, 'accept');

    $offer->refresh();
    expect($offer->status)->toBe(CounterOfferStatus::Accepted)
        ->and($offer->resulting_quote_id)->not->toBeNull();

    $revision = Quote::findOrFail($offer->resulting_quote_id);

    expect($revision->revision)->toBe(2)
        ->and($revision->supersedes_quote_id)->toBe($quote->getKey())
        ->and($revision->status)->toBe(QuoteStatus::Submitted)
        ->and($revision->company_id)->toBe($company->getKey())
        // Totals recomputed by us from the line, not copied from the offer.
        ->and((string) $revision->total_amount)->toBe('59000.00')
        ->and($revision->items)->toHaveCount(1)
        ->and((string) $revision->items->first()->unit_price)->toBe('590.00')
        ->and((string) $revision->items->first()->quantity)->toBe('100.00');

    // The predecessor was withdrawn, which is what keeps the partial unique
    // index (one non-withdrawn quote per supplier per RFQ) satisfied.
    expect($quote->refresh()->status)->toBe(QuoteStatus::Withdrawn);

    // Both a revised quotation card and the updated thread pointer.
    expect($conversation->messages()->where('type', MessageType::Quotation->value)->where('related_id', $revision->getKey())->exists())->toBeTrue()
        ->and($conversation->refresh()->quote_id)->toBe($revision->getKey());
});

it('lets the buyer accept the revised quote and get the order at the agreed price', function () {
    [$conversation, $buyer, , $staff, $quote] = ccScene();

    $offer = ccCommerce()->counter($conversation, $quote, $buyer, ['unit_price' => 590, 'quantity' => 100]);
    ccCommerce()->respondToCounter($offer, $staff, 'accept');

    $revision = Quote::findOrFail($offer->refresh()->resulting_quote_id);

    ccCommerce()->acceptQuotation($conversation, $revision, $buyer);

    $order = Order::where('quote_id', $revision->getKey())->firstOrFail();

    expect((string) $order->total_amount)->toBe('59000.00');
});

it('supersedes the live round rather than stacking counters', function () {
    [$conversation, $buyer, , $staff, $quote] = ccScene();

    $first = ccCommerce()->counter($conversation, $quote, $buyer, ['unit_price' => 570]);

    // The same side cannot counter twice in a row.
    expect(fn () => ccCommerce()->counter($conversation, $quote, $buyer, ['unit_price' => 560]))
        ->toThrow(RuntimeException::class);

    // The other side countering closes the open round.
    $second = ccCommerce()->counter($conversation, $quote, $staff, ['unit_price' => 590]);

    expect($first->refresh()->status)->toBe(CounterOfferStatus::Superseded)
        ->and($second->status)->toBe(CounterOfferStatus::Pending);

    // One live round per quote, backed by the partial unique index.
    expect(QuoteCounterOffer::where('quote_id', $quote->getKey())->pending()->count())->toBe(1);

    expect(fn () => DB::table('quote_counter_offers')->insert([
        'quote_id' => $quote->getKey(),
        'conversation_id' => $conversation->getKey(),
        'proposed_by_user_id' => $buyer->getKey(),
        'party' => 'buyer',
        'currency' => 'USD',
        'unit_price' => 1,
        'total_amount' => 1,
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('refuses to negotiate a settled quote', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    ccCommerce()->acceptQuotation($conversation, $quote, $buyer);

    expect(fn () => ccCommerce()->counter($conversation, $quote->refresh(), $buyer, ['unit_price' => 500]))
        ->toThrow(RuntimeException::class);

    expect(QuoteCounterOffer::count())->toBe(0);
});

it('refuses a counter-offer response from a non-participant', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    $offer = ccCommerce()->counter($conversation, $quote, $buyer, ['unit_price' => 570]);
    $stranger = User::factory()->create(['email' => 'ccout'.uniqid().'@example.com']);

    // 404 from MessagingService, before the role is even considered.
    expect(fn () => ccCommerce()->respondToCounter($offer, $stranger, 'accept'))
        ->toThrow(HttpException::class);

    $this->actingAs($stranger)
        ->post(route('chat.counter.respond', [$conversation, $offer]), ['decision' => 'accept'])
        ->assertNotFound();

    expect($offer->refresh()->status)->toBe(CounterOfferStatus::Pending);
});

it('refuses a counter-offer that belongs to another thread', function () {
    [$conversationA, $buyerA, , , $quoteA] = ccScene();
    [$conversationB, $buyerB] = ccScene();

    $offer = ccCommerce()->counter($conversationA, $quoteA, $buyerA, ['unit_price' => 570]);

    $this->actingAs($buyerB)
        ->post(route('chat.counter.respond', [$conversationB, $offer]), ['decision' => 'accept'])
        ->assertNotFound();
});

/* ------------------------------------------------------------ Livewire UI */

it('exposes the buyer actions and hides them from the supplier', function () {
    [$conversation, $buyer, , $staff, $quote] = ccScene();

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $conversation->getKey()])
        ->assertSee('Accept quotation')
        ->assertSee('Post a requirement')
        ->assertDontSee('Withdraw quotation');

    Livewire::actingAs($staff)
        ->test(Thread::class, ['conversationId' => $conversation->getKey()])
        ->assertSee('Withdraw quotation')
        ->assertDontSee('Accept quotation')
        ->assertDontSee('Post a requirement');
});

it('accepts a quotation through the Livewire card', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $conversation->getKey()])
        ->call('acceptQuote', $quote->getKey())
        ->assertHasNoErrors();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Accepted);
});

it('refuses a Livewire accept from the supplier side', function () {
    [$conversation, , , $staff, $quote] = ccScene();

    Livewire::actingAs($staff)
        ->test(Thread::class, ['conversationId' => $conversation->getKey()])
        ->call('acceptQuote', $quote->getKey())
        ->assertForbidden();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Submitted);
});

it('will not act on a quote from another company', function () {
    [$conversation, $buyer] = ccScene();
    [, , , , $otherQuote] = ccScene();

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $conversation->getKey()])
        ->call('acceptQuote', $otherQuote->getKey())
        ->assertStatus(404);

    expect($otherQuote->refresh()->status)->toBe(QuoteStatus::Submitted);
});

/* ------------------------------------------------------------ rate limits */

it('rate-limits chat RFQ submissions', function () {
    [$conversation, $buyer] = ccScene();

    RateLimiter::clear('chat-rfq:'.$buyer->getKey());

    // The limiter is per account, 12/hour, and is enforced inside the Livewire
    // action as well as on the route — Livewire never sees route middleware.
    for ($i = 0; $i < 12; $i++) {
        RateLimiter::hit('chat-rfq:'.$buyer->getKey(), 3600);
    }

    Livewire::actingAs($buyer)
        ->test(Thread::class, ['conversationId' => $conversation->getKey()])
        ->set('rfqForm.species_text', 'Sapele')
        ->set('rfqForm.quantity', '10')
        ->set('rfqForm.unit', 'm3')
        ->call('submitRfq')
        ->assertHasErrors('rfqForm.species_text');

    expect(Rfq::where('source', 'chat')->count())->toBe(0);
});

it('rate-limits quotation decisions on the route', function () {
    [$conversation, $buyer, , , $quote] = ccScene();

    RateLimiter::clear('chat-decision:'.$buyer->getKey());

    for ($i = 0; $i < 11; $i++) {
        $response = $this->actingAs($buyer)->post(route('chat.quote.counter', [$conversation, $quote]), ['unit_price' => 500 + $i]);
    }

    expect($response->status())->toBe(429);
});

/* ----------------------------------------------------------- query budget */

it('renders a thread full of commerce cards in a bounded number of queries', function () {
    [$conversation, $buyer, $company, $staff, $quote, $rfq] = ccScene();

    // Twenty extra quotation cards, each pointing at its own live Quote, plus
    // acceptance and counter-offer cards. A per-card lookup would show up here
    // immediately as a linear query count.
    for ($i = 0; $i < 20; $i++) {
        // Each on its own RFQ: the partial unique index allows only one active
        // quote per supplier per request, and the point here is the render, not
        // the routing.
        $extra = Quote::factory()->submitted()->create([
            'reference_code' => 'QTE-EX-'.$i.'-'.uniqid(),
        ]);
        QuoteItem::factory()->create(['quote_id' => $extra->getKey(), 'quantity' => 10, 'unit_price' => 100]);
        $extra->load('items')->recalculateTotals()->save();

        app(MessagingService::class)->postQuotation($conversation, $staff, $extra);
    }

    ccCommerce()->counter($conversation, $quote, $buyer, ['unit_price' => 570]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(MessagingService::class)->messages($conversation->refresh());

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    // 21 quotation cards + a counter-offer card + the Phase 1 cards, in well
    // under one query per card.
    expect($count)->toBeLessThan(15);
});
