<?php

use App\Enums\TradeAssuranceMilestoneStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\TradeAssuranceAgreement;
use App\Models\User;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * A real Order via the award path (mirrors OrderTest/TradeAssuranceTest's
 * helper), attached to $buyer by forcing `user_id` — factories leave RFQs
 * guest by default and Order has no buyer-company FK to hook into instead.
 */
function apiOrder(?User $buyer = null): Order
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create($buyer ? ['user_id' => $buyer->getKey()] : []);
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

    app(QuoteService::class)->accept($quote);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    return $order->fresh();
}

/* -------------------------------------------------------------- listing */

it('lists only the buyer own orders', function () {
    $buyer = User::factory()->create();
    $mine = apiOrder($buyer);
    apiOrder(User::factory()->create()); // someone else's

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.reference'))->toBe($mine->reference_code);
});

it('requires buyer auth to list orders', function () {
    $this->getJson('/api/v1/orders')->assertUnauthorized();
});

/* ----------------------------------------------------------------- show */

it('shows a single order the buyer owns, including line items', function () {
    $buyer = User::factory()->create();
    $order = apiOrder($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code)
        ->assertOk()
        ->assertJsonPath('data.reference', $order->reference_code)
        ->assertJsonPath('data.total_amount', '18500.00')
        ->assertJsonPath('data.items.0.line_total', '18500.00')
        ->assertJsonPath('data.has_trade_assurance', false);
});

it('404s another buyer order rather than confirming it exists', function () {
    $buyer = User::factory()->create();
    $theirs = apiOrder(User::factory()->create());

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/orders/'.$theirs->reference_code)->assertNotFound();
});

it('404s a guest (accountless) order for any authenticated buyer', function () {
    $buyer = User::factory()->create();
    $guestOrder = apiOrder(); // no user_id

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/orders/'.$guestOrder->reference_code)->assertNotFound();
});

/* ----------------------------------------------------- trade assurance: view */

it('reports no Trade Assurance agreement when none is set up yet', function () {
    $buyer = User::factory()->create();
    $order = apiOrder($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/trade-assurance')
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('shows the Trade Assurance agreement and its milestones', function () {
    $buyer = User::factory()->create();
    $order = apiOrder($buyer);
    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/trade-assurance')
        ->assertOk()
        ->assertJsonCount(4, 'data.milestones');

    expect($response->json('data.milestones.0.title'))->toBe('Order Confirmed')
        ->and($response->json('data.milestones.0.can_confirm'))->toBeTrue();
    expect($agreement->exists)->toBeTrue();
});

it('404s Trade Assurance for another buyer order', function () {
    $buyer = User::factory()->create();
    $theirs = apiOrder(User::factory()->create());
    TradeAssuranceAgreement::createDefaultMilestones($theirs);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$theirs->reference_code.'/trade-assurance')
        ->assertNotFound();
});

/* -------------------------------------------------- trade assurance: confirm */

it('lets the buyer confirm a milestone via the API', function () {
    $buyer = User::factory()->create();
    $order = apiOrder($buyer);
    $agreement = TradeAssuranceAgreement::createDefaultMilestones($order);
    $milestone = $agreement->milestones->first();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/trade-assurance/milestones/{$milestone->id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.milestones.0.status', 'buyer_confirmed');

    expect($milestone->fresh()->status)->toBe(TradeAssuranceMilestoneStatus::BuyerConfirmed)
        ->and($milestone->fresh()->confirmed_by)->toBe($buyer->getKey());
});

it('404s a milestone confirm on another buyer order', function () {
    $buyer = User::factory()->create();
    $theirs = apiOrder(User::factory()->create());
    $agreement = TradeAssuranceAgreement::createDefaultMilestones($theirs);
    $milestone = $agreement->milestones->first();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$theirs->reference_code}/trade-assurance/milestones/{$milestone->id}/confirm")
        ->assertNotFound();

    expect($milestone->fresh()->status)->toBe(TradeAssuranceMilestoneStatus::Pending);
});

it('404s confirming a milestone id that does not belong to the order agreement', function () {
    $buyer = User::factory()->create();
    $order = apiOrder($buyer);
    TradeAssuranceAgreement::createDefaultMilestones($order);

    $otherOrder = apiOrder(User::factory()->create());
    $otherAgreement = TradeAssuranceAgreement::createDefaultMilestones($otherOrder);
    $otherMilestone = $otherAgreement->milestones->first();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/trade-assurance/milestones/{$otherMilestone->id}/confirm")
        ->assertNotFound();
});

it('404s confirming a milestone when no agreement exists yet', function () {
    $buyer = User::factory()->create();
    $order = apiOrder($buyer);

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->reference_code}/trade-assurance/milestones/1/confirm")
        ->assertNotFound();
});
