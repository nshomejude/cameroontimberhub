<?php

use App\Enums\CompanyUserRole;
use App\Enums\MessageType;
use App\Enums\QuoteStatus;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Quote;
use App\Models\QuoteCounterOffer;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* ------------------------------------------------------------------ helpers */
/* Prefixed `chatApi` so they never collide with ChatCommerceTest's `cc*`
   helpers or ConversationApiTest's `apiConversation()` — Pest loads every
   feature file into the same process. */

function chatApiCommerce(): ChatCommerceService
{
    return app(ChatCommerceService::class);
}

/**
 * A negotiable thread: a buyer, a verified supplier company, an approved RFQ
 * routed to that company, a submitted single-line quote already posted as a
 * card in an open conversation.
 *
 * @return array{0: Conversation, 1: User, 2: Company, 3: User, 4: Quote, 5: Rfq}
 */
function chatApiScene(array $quoteOverrides = []): array
{
    $company = Company::factory()->publiclyVisible()->create();
    $staff = User::factory()->create(['email' => 'capistaff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    $buyer = User::factory()->create([
        'email' => 'capibuyer'.uniqid().'@example.com',
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

    chatApiCommerce()->issueQuotation($conversation, $quote->refresh(), $staff);

    return [$conversation->refresh(), $buyer, $company, $staff, $quote->refresh(), $rfq->refresh()];
}

function chatApiQuoteCard(Conversation $conversation, Quote $quote)
{
    return $conversation->messages()
        ->where('type', MessageType::Quotation->value)
        ->where('related_id', $quote->getKey())
        ->firstOrFail();
}

/* ================================================================== RFQ */

it('creates a chat RFQ through the API and returns the resulting card', function () {
    [$conversation, $buyer] = chatApiScene();

    RateLimiter::clear('chat-rfq:'.$buyer->getKey());

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/rfq", [
            'species_text' => 'Sapele',
            'quantity' => 50,
            'unit' => 'm3',
        ])
        ->assertCreated();

    expect($response->json('data.kind'))->toBe('rfq_reference');

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")
        ->assertOk()
        ->assertJsonFragment(['kind' => 'rfq_reference']);
});

it('refuses a chat RFQ from the supplier side with a real 403, not a 409', function () {
    [$conversation, , , $staff] = chatApiScene();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/rfq", [
            'species_text' => 'Sapele',
            'quantity' => 50,
            'unit' => 'm3',
        ])
        ->assertForbidden();
});

/* ============================================================== QUOTES */

it('lets the buyer accept a quotation and returns the contract-acceptance card', function () {
    [$conversation, $buyer, , , $quote] = chatApiScene();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/accept")
        ->assertCreated();

    expect($response->json('data.kind'))->toBe('contract_acceptance')
        ->and($quote->refresh()->status)->toBe(QuoteStatus::Accepted);

    expect($conversation->refresh()->order_id)->not->toBeNull();
});

it('blocks the supplier from accepting their own quotation', function () {
    [$conversation, , , $staff, $quote] = chatApiScene();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/accept")
        ->assertForbidden();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Submitted);
});

it('409s a repeat accept on an already-accepted quote', function () {
    [$conversation, $buyer, , , $quote] = chatApiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/accept")
        ->assertCreated();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/accept")
        ->assertStatus(409);

    expect($response->json('error.code'))->toBe('quote_not_actionable');
});

it('lets the buyer decline a quotation', function () {
    [$conversation, $buyer, , , $quote] = chatApiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/decline", ['reason' => 'Price too high.'])
        ->assertOk();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Declined);
});

it('lets the supplier withdraw their own quotation, and blocks the buyer from withdrawing it', function () {
    [$conversation, $buyer, , $staff, $quote] = chatApiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/withdraw")
        ->assertForbidden();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/withdraw")
        ->assertOk();

    expect($quote->refresh()->status)->toBe(QuoteStatus::Withdrawn);
});

/* ========================================================== NEGOTIATION */

it('lets either side open a counter-offer and the other side accept it, issuing a revised quotation', function () {
    [$conversation, $buyer, , $staff, $quote] = chatApiScene();

    $counterResponse = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/counter", [
            'unit_price' => 580,
        ])
        ->assertCreated();

    expect($counterResponse->json('data.kind'))->toBe('counter_offer');

    $offer = QuoteCounterOffer::where('quote_id', $quote->getKey())->firstOrFail();

    // The proposer may not answer their own round.
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/counter-offers/{$offer->id}/respond", ['decision' => 'accept'])
        ->assertForbidden();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/counter-offers/{$offer->id}/respond", ['decision' => 'accept'])
        ->assertOk();

    expect($offer->refresh()->status->value)->toBe('accepted');

    $revised = Quote::where('rfq_id', $quote->rfq_id)->where('company_id', $quote->company_id)
        ->where('status', QuoteStatus::Submitted)->firstOrFail();

    expect((float) $revised->items->first()->unit_price)->toBe(580.0);
});

it('lets the awaiting party decline a counter-offer', function () {
    [$conversation, $buyer, , $staff, $quote] = chatApiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/counter", ['unit_price' => 580])
        ->assertCreated();

    $offer = QuoteCounterOffer::where('quote_id', $quote->getKey())->firstOrFail();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/counter-offers/{$offer->id}/respond", ['decision' => 'decline'])
        ->assertOk();

    expect($offer->refresh()->status->value)->toBe('declined');
});

/* ================================================================ actions[] */

it('computes actions[] for a pending quotation: buyer gets accept/decline/counter, supplier gets withdraw', function () {
    [$conversation, $buyer, , $staff, $quote] = chatApiScene();

    $buyerView = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")
        ->assertOk();

    $card = collect($buyerView->json('data'))->firstWhere('kind', 'quotation');
    $keys = collect($card['actions'])->pluck('key')->all();
    expect($keys)->toEqualCanonicalizing(['counter', 'decline', 'accept']);

    $supplierView = $this->actingAs($staff, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")
        ->assertOk();

    $supplierCard = collect($supplierView->json('data'))->firstWhere('kind', 'quotation');
    expect(collect($supplierCard['actions'])->pluck('key')->all())->toBe(['withdraw']);
});

it('clears actions[] on a quotation once it is accepted', function () {
    [$conversation, $buyer, , , $quote] = chatApiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/accept")
        ->assertCreated();

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")
        ->assertOk();

    $card = collect($response->json('data'))->firstWhere('kind', 'quotation');
    expect($card['actions'])->toBe([]);
});

it('gives the awaiting party respond actions on a counter-offer and nothing to the proposer', function () {
    [$conversation, $buyer, , $staff, $quote] = chatApiScene();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/counter", ['unit_price' => 580])
        ->assertCreated();

    $buyerView = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk();
    $buyerCard = collect($buyerView->json('data'))->firstWhere('kind', 'counter_offer');
    expect($buyerCard['actions'])->toBe([]);

    $supplierView = $this->actingAs($staff, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/messages")->assertOk();
    $supplierCard = collect($supplierView->json('data'))->firstWhere('kind', 'counter_offer');
    expect(collect($supplierCard['actions'])->pluck('key')->all())->toEqualCanonicalizing(['decline', 'counter', 'accept']);
});

/* =========================================================== composer_actions */

it('shows create_rfq in composer_actions for the buyer and nothing for the supplier', function () {
    [$conversation, $buyer, , $staff] = chatApiScene();

    $buyerView = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}")->assertOk();
    expect(collect($buyerView->json('data.composer_actions'))->pluck('key')->all())->toBe(['create_rfq']);

    $supplierView = $this->actingAs($staff, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}")->assertOk();
    expect($supplierView->json('data.composer_actions'))->toBe([]);
});

/* ============================================================== ENUMERATION */

it('404s every commerce endpoint for a non-participant', function () {
    [$conversation, , , , $quote] = chatApiScene();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/rfq", ['species_text' => 'x', 'quantity' => 1, 'unit' => 'm3'])
        ->assertNotFound();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/accept")
        ->assertNotFound();
});

it('requires auth for every commerce endpoint', function () {
    [$conversation, , , , $quote] = chatApiScene();

    $this->postJson("/api/v1/conversations/{$conversation->id}/rfq")->assertUnauthorized();
    $this->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/accept")->assertUnauthorized();
    $this->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/decline")->assertUnauthorized();
    $this->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/withdraw")->assertUnauthorized();
    $this->postJson("/api/v1/conversations/{$conversation->id}/quotes/{$quote->id}/counter")->assertUnauthorized();
});
