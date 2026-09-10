<?php

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Models\Company;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\DisputeService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * A real Order with a genuine buyer and supplier company, mirroring
 * DisputeLifecycleTest's own helper so API and web tests build orders the
 * same way.
 *
 * @return array{0: Order, 1: User, 2: Company}
 */
function apiDisputeOrder(): array
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $buyer = User::factory()->create();

    $rfq = Rfq::factory()->approved()->create(['buyer_email' => $buyer->email, 'user_id' => $buyer->getKey()]);
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplierCompany->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplierCompany->getKey(),
        'rfq_company_id' => $routing->getKey(),
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(100, 185.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote, $buyer);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    return [$order->fresh(), $buyer, $supplierCompany];
}

/* -------------------------------------------------------------- listing */

it('lists disputes on an order the buyer is a party to', function () {
    [$order, $buyer] = apiDisputeOrder();
    app(DisputeService::class)->open($order, $buyer, DisputeCategory::Quality, 'Grade mismatch.');

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/orders/{$order->reference_code}/disputes")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.category', 'quality')
        ->assertJsonPath('data.0.status', 'opened');
});

it('404s the dispute list for another buyer order rather than confirming it exists', function () {
    [$theirs] = apiDisputeOrder();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/v1/orders/{$theirs->reference_code}/disputes")
        ->assertNotFound();
});

it('requires buyer auth to list disputes', function () {
    [$order] = apiDisputeOrder();

    $this->getJson("/api/v1/orders/{$order->reference_code}/disputes")->assertUnauthorized();
});

/* ----------------------------------------------------------------- show */

it('shows a single dispute the buyer is a party to', function () {
    [$order, $buyer] = apiDisputeOrder();
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Delay, 'Late shipment.');

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $dispute->id)
        ->assertJsonPath('data.description', 'Late shipment.');
});

it('404s a dispute on another buyer order', function () {
    [$theirs, $theirBuyer] = apiDisputeOrder();
    $dispute = app(DisputeService::class)->open($theirs, $theirBuyer, DisputeCategory::Other, 'Misc.');

    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/v1/orders/{$theirs->reference_code}/disputes/{$dispute->id}")
        ->assertNotFound();
});

it('404s a dispute id that does not belong to the given order', function () {
    [$orderA, $buyerA] = apiDisputeOrder();
    [$orderB, $buyerB] = apiDisputeOrder();
    $disputeOnB = app(DisputeService::class)->open($orderB, $buyerB, DisputeCategory::Other, 'On order B.');

    $this->actingAs($buyerA, 'sanctum')
        ->getJson("/api/v1/orders/{$orderA->reference_code}/disputes/{$disputeOnB->id}")
        ->assertNotFound();
});

/* ---------------------------------------------------------------- store */

it('lets the buyer open a dispute via the API', function () {
    [$order, $buyer] = apiDisputeOrder();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/disputes", [
            'category' => 'quantity',
            'description' => 'Short shipment — 20 fewer m3 than invoiced.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.category', 'quantity')
        ->assertJsonPath('data.status', 'opened');

    $dispute = Dispute::findOrFail($response->json('data.id'));

    expect($dispute->status)->toBe(DisputeStatus::Opened)
        ->and($dispute->raised_by_user_id)->toBe($buyer->id)
        ->and($dispute->order_id)->toBe($order->id);
});

it('produces the same Dispute state opening via the API as opening via the web', function () {
    [$order, $buyer] = apiDisputeOrder();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/disputes", [
            'category' => 'quality',
            'description' => 'Same as the web flow.',
        ])
        ->assertCreated();

    $dispute = Dispute::where('order_id', $order->id)->firstOrFail();

    expect($dispute->status)->toBe(DisputeStatus::Opened)
        ->and($dispute->category)->toBe(DisputeCategory::Quality)
        ->and($dispute->raised_by_user_id)->toBe($buyer->id)
        ->and($dispute->respondent_company_id)->not->toBeNull();
});

it('404s opening a dispute on another buyer order', function () {
    [$theirs] = apiDisputeOrder();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/orders/{$theirs->reference_code}/disputes", [
            'category' => 'quality',
            'description' => 'Not my order.',
        ])
        ->assertNotFound();

    expect(Dispute::where('order_id', $theirs->id)->exists())->toBeFalse();
});

it('validates category and description when opening a dispute', function () {
    [$order, $buyer] = apiDisputeOrder();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/disputes", [
            'category' => 'not-a-real-category',
            'description' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category', 'description'], 'error.details');
});

it('requires buyer auth to open a dispute', function () {
    [$order] = apiDisputeOrder();

    $this->postJson("/api/v1/orders/{$order->reference_code}/disputes", [
        'category' => 'quality',
        'description' => 'Anonymous attempt.',
    ])->assertUnauthorized();
});

/* ---------------------------------------------------------------- reply */

it('lets the buyer reply to their own dispute via the API', function () {
    [$order, $buyer] = apiDisputeOrder();
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Payment, 'Payment discrepancy.');
    // Move the dispute into CounterpartyResponsePending first — reply()
    // (via Dispute::respondentReply()) is only valid from that status,
    // matching the web flow's own lifecycle order.
    app(DisputeService::class)->submitEvidence($dispute, $buyer, 'Supporting invoice attached.');

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/reply", [
            'body' => 'Following up with more detail.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'under_review')
        ->assertJsonCount(1, 'data.messages');

    expect($dispute->fresh()->status)->toBe(DisputeStatus::UnderReview);
});

it('404s replying to a dispute on another buyer order', function () {
    [$theirs, $theirBuyer] = apiDisputeOrder();
    $dispute = app(DisputeService::class)->open($theirs, $theirBuyer, DisputeCategory::Payment, 'Payment issue.');
    app(DisputeService::class)->submitEvidence($dispute, $theirBuyer, 'Evidence.');
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/orders/{$theirs->reference_code}/disputes/{$dispute->id}/reply", [
            'body' => 'Butting in.',
        ])
        ->assertNotFound();

    expect($dispute->fresh()->status)->toBe(DisputeStatus::CounterpartyResponsePending);
});

it('validates the reply body', function () {
    [$order, $buyer] = apiDisputeOrder();
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Payment, 'Payment issue.');
    app(DisputeService::class)->submitEvidence($dispute, $buyer, 'Evidence.');

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/reply", [
            'body' => '',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body'], 'error.details');
});

it('requires buyer auth to reply to a dispute', function () {
    [$order, $buyer] = apiDisputeOrder();
    $dispute = app(DisputeService::class)->open($order, $buyer, DisputeCategory::Payment, 'Payment issue.');
    app(DisputeService::class)->submitEvidence($dispute, $buyer, 'Evidence.');

    $this->postJson("/api/v1/orders/{$order->reference_code}/disputes/{$dispute->id}/reply", [
        'body' => 'Anonymous attempt.',
    ])->assertUnauthorized();
});
