<?php

use App\Enums\QuoteStatus;
use App\Enums\RfqStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/** An approved RFQ owned by $buyer, with one submitted quote per supplier. */
function apiQuoteContext(User $buyer, int $suppliers = 1): array
{
    $rfq = Rfq::factory()->approved()->create([
        'user_id' => $buyer->id,
        'buyer_email' => $buyer->email,
    ]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3']);

    $quotes = collect(range(1, $suppliers))->map(function () use ($rfq) {
        $company = Company::factory()->publiclyVisible()->create();

        $routing = RfqCompany::create([
            'rfq_id' => $rfq->getKey(),
            'company_id' => $company->getKey(),
            'status' => 'sent',
            'routed_at' => now(),
        ]);

        $quote = Quote::factory()->submitted()->create([
            'rfq_id' => $rfq->getKey(),
            'company_id' => $company->getKey(),
            'rfq_company_id' => $routing->getKey(),
        ]);

        QuoteItem::factory()->create(['quote_id' => $quote->getKey(), 'quantity' => 100, 'unit_price' => 185]);

        // The service owns every derived figure — line totals included — so the
        // fixture money is produced exactly the way a real submission produces it.
        app(QuoteService::class)->recalculate($quote);

        return $quote->fresh();
    });

    return [$rfq, $quotes];
}

/* -------------------------------------------------------------- reading */

it('lists the quotes received on the buyer own RFQ', function () {
    $buyer = User::factory()->create();
    [$rfq, $quotes] = apiQuoteContext($buyer, suppliers: 2);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/rfqs/'.$rfq->reference_code.'/quotes')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect(collect($response->json('data'))->pluck('reference')->sort()->values()->all())
        ->toBe($quotes->pluck('reference_code')->sort()->values()->all());
});

it('hides draft and withdrawn quotes from the buyer entirely', function () {
    $buyer = User::factory()->create();
    [$rfq, $quotes] = apiQuoteContext($buyer);

    $draft = Quote::factory()->create(['rfq_id' => $rfq->getKey(), 'status' => QuoteStatus::Draft]);
    $withdrawn = Quote::factory()->create(['rfq_id' => $rfq->getKey(), 'status' => QuoteStatus::Withdrawn]);

    $body = json_encode($this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/rfqs/'.$rfq->reference_code.'/quotes')->assertOk()->json());

    expect($body)->not->toContain($draft->reference_code)->not->toContain($withdrawn->reference_code);

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/quotes/'.$draft->reference_code)->assertNotFound();
    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/quotes/'.$withdrawn->reference_code)->assertNotFound();
});

it('returns quote detail with line items and totals and marks it viewed', function () {
    $buyer = User::factory()->create();
    [, $quotes] = apiQuoteContext($buyer);
    $quote = $quotes->first();

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/quotes/'.$quote->reference_code)
        ->assertOk()
        ->assertJsonPath('data.reference', $quote->reference_code)
        ->assertJsonPath('data.total_amount', '18500.00')
        ->assertJsonPath('data.items.0.line_total', '18500.00')
        ->assertJsonPath('data.status', 'viewed')
        ->assertJsonPath('data.is_actionable', true)
        ->assertJsonStructure(['data' => ['supplier' => ['slug', 'name'], 'items', 'valid_until', 'payment_terms']]);
});

it('404s another buyer quote rather than confirming it exists', function () {
    $buyer = User::factory()->create();
    [, $theirs] = apiQuoteContext(User::factory()->create());
    $quote = $theirs->first();

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/quotes/'.$quote->reference_code)->assertNotFound();
    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/quotes/'.$quote->reference_code.'/accept')->assertNotFound();
    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/quotes/'.$quote->reference_code.'/decline', ['reason' => 'Too expensive'])
        ->assertNotFound();

    // And it really is untouched.
    expect($quote->fresh()->status)->toBe(QuoteStatus::Submitted);
});

/* --------------------------------------------------------------- accept */

it('accepts a quote, declines its siblings, closes the RFQ and creates the order', function () {
    $buyer = User::factory()->create();
    [$rfq, $quotes] = apiQuoteContext($buyer, suppliers: 3);
    $winner = $quotes->first();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/quotes/'.$winner->reference_code.'/accept')
        ->assertOk()
        ->assertJsonPath('data.quote.status', 'accepted');

    expect($winner->fresh()->status)->toBe(QuoteStatus::Accepted)
        ->and($rfq->fresh()->status)->toBe(RfqStatus::Closed);

    foreach ($quotes->skip(1) as $sibling) {
        expect($sibling->fresh()->status)->toBe(QuoteStatus::Declined)
            ->and($sibling->fresh()->decline_reason)->toBe('Another quote was accepted for this request.');
    }

    $order = Order::where('quote_id', $winner->getKey())->firstOrFail();

    expect($response->json('data.order.reference'))->toBe($order->reference_code)
        ->and((int) $order->user_id)->toBe($buyer->id);
});

it('rejects a second accept on the same quote, and the database backs the guard up', function () {
    $buyer = User::factory()->create();
    [, $quotes] = apiQuoteContext($buyer, suppliers: 2);
    $winner = $quotes->first();
    $loser = $quotes->last();

    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/quotes/'.$winner->reference_code.'/accept')->assertOk();

    // The service guard: an already-settled quote cannot be actioned again.
    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/quotes/'.$winner->reference_code.'/accept')->assertStatus(409);
    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/quotes/'.$winner->reference_code.'/decline', ['reason' => 'Changed my mind'])
        ->assertStatus(409);

    expect(Order::count())->toBe(1);

    // The database constraint underneath it: one accepted quote per RFQ, full stop.
    expect(fn () => DB::table('quotes')->where('id', $loser->getKey())->update(['status' => 'accepted']))
        ->toThrow(QueryException::class);
});

it('refuses to accept an expired quote', function () {
    $buyer = User::factory()->create();
    [$rfq] = apiQuoteContext($buyer);

    $company = Company::factory()->publiclyVisible()->create();
    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now(),
    ]);
    $stale = Quote::factory()->expired()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'rfq_company_id' => $routing->getKey(),
    ]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/quotes/'.$stale->reference_code.'/accept')
        ->assertStatus(409);

    expect(Order::count())->toBe(0);
});

/* -------------------------------------------------------------- decline */

it('declines a quote with a reason and records it', function () {
    $buyer = User::factory()->create();
    [, $quotes] = apiQuoteContext($buyer);
    $quote = $quotes->first();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/quotes/'.$quote->reference_code.'/decline', ['reason' => 'Lead time is too long for us'])
        ->assertOk()
        ->assertJsonPath('data.status', 'declined')
        ->assertJsonPath('data.decline_reason', 'Lead time is too long for us');

    expect($quote->fresh()->status)->toBe(QuoteStatus::Declined)
        // Declining one quote never settles the request itself.
        ->and(Order::count())->toBe(0);
});

it('422s a decline with no reason', function () {
    $buyer = User::factory()->create();
    [, $quotes] = apiQuoteContext($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/quotes/'.$quotes->first()->reference_code.'/decline', ['reason' => ''])
        ->assertStatus(422)
        ->assertJsonValidationErrors('reason');

    expect($quotes->first()->fresh()->status)->toBe(QuoteStatus::Submitted);
});

/* ------------------------------------------------------- payload hygiene */

it('never leaks another buyer email, an internal note or a receipt token through quote payloads', function () {
    $buyer = User::factory()->create();
    [$rfq, $quotes] = apiQuoteContext($buyer);

    // A second buyer with a distinctive address that must never appear.
    Rfq::factory()->create(['buyer_email' => 'other-buyer@nowhere.test', 'user_id' => User::factory()->create()->id]);

    $this->actingAs($buyer, 'sanctum')->postJson('/api/v1/quotes/'.$quotes->first()->reference_code.'/accept')->assertOk();

    foreach ([
        '/api/v1/rfqs/'.$rfq->reference_code.'/quotes',
        '/api/v1/quotes/'.$quotes->first()->reference_code,
    ] as $url) {
        $body = json_encode($this->actingAs($buyer, 'sanctum')->getJson($url)->assertOk()->json());

        expect($body)
            ->not->toContain('other-buyer@nowhere.test')
            ->not->toContain('verification_token')
            ->not->toContain('rfq_company_id')
            ->not->toContain('spam_score')
            ->not->toContain('ip_address');
    }
});

it('holds the quotes listing to a bounded query count', function () {
    $buyer = User::factory()->create();
    apiQuoteContext($buyer, suppliers: 5);
    $rfq = Rfq::where('user_id', $buyer->id)->firstOrFail();

    DB::enableQueryLog();
    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/rfqs/'.$rfq->reference_code.'/quotes')->assertOk()->assertJsonCount(5, 'data');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThan(20);
});
