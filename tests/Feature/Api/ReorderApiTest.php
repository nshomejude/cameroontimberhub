<?php

use App\Enums\MessageType;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\MessagingService;
use App\Services\OrderLifecycleService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * A completed order, its conversation, and both parties — built through the
 * real award path exactly like `ReorderTest::roScene()`, because eligibility
 * is derived from that history and a faked one would test nothing. Prefixed
 * `reapi` so these helpers never collide with `ReorderTest.php`'s `ro*` ones —
 * Pest loads every feature file into one process.
 *
 * @return array{0: \App\Models\Conversation, 1: User, 2: Company, 3: User, 4: Order}
 */
function reapiScene(float $unitPrice = 620.00): array
{
    $plan = Plan::factory()->create(['features' => ['leads_receive' => true]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);
    $staff = User::factory()->create(['email' => 'reapistaff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => \App\Enums\CompanyUserRole::Owner->value, 'is_primary' => true]);

    $buyer = User::factory()->create([
        'email' => 'reapibuyer'.uniqid().'@example.com',
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

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
        'currency' => 'USD',
        'incoterm' => 'CIF',
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'rfq_item_id' => $rfqItem->getKey(),
        'description' => 'Premium Sapele Lumber (KD)',
        'quantity' => 50,
        'unit' => 'm3',
        'unit_price' => $unitPrice,
        'line_total' => Quote::lineTotal(50, $unitPrice),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    $conversation = app(MessagingService::class)->start($buyer, $company);
    $commerce = app(ChatCommerceService::class);
    $commerce->issueQuotation($conversation, $quote->refresh(), $staff);
    $commerce->acceptQuotation($conversation, $quote->refresh(), $buyer);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    $lifecycle = app(OrderLifecycleService::class);
    $lifecycle->confirm($conversation, $order->refresh(), $staff);
    $lifecycle->startProduction($conversation, $order->refresh(), $staff);
    $lifecycle->ship($conversation, $order->refresh(), $staff);
    $lifecycle->deliver($conversation, $order->refresh(), $staff);
    $lifecycle->complete($conversation, $order->refresh(), $buyer);

    return [$conversation->refresh(), $buyer, $company, $staff, $order->refresh()];
}

/* ============================================================ ELIGIBILITY */

it('reports eligible with prefilled lines for a delivered/completed order', function () {
    [, $buyer, , , $order] = reapiScene(620.00);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertOk()
        ->assertJsonPath('data.eligible', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.in_progress', false);

    $lines = $response->json('data.lines');

    expect($lines)->toHaveCount(1)
        ->and($lines[0]['description'])->toBe('Premium Sapele Lumber (KD)')
        ->and($lines[0]['quantity'])->toBe('50.00')
        ->and($lines[0]['previous_unit_price'])->toBe('620.00');
});

it('reports ineligible with the real refusal reason for a cancelled order', function () {
    [, $buyer, , , $order] = reapiScene();

    \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->getKey())
        ->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertOk()
        ->assertJsonPath('data.eligible', false)
        ->assertJsonPath('data.lines', null);

    expect($response->json('data.reason'))
        ->toBe('This order was cancelled. Start a new request instead of reordering it.');
});

it('reports ineligible with the real refusal reason for an order that has not landed yet', function () {
    [$c, $buyer, $company, $staff] = reapiScene();

    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey(), 'buyer_email' => $buyer->email]);
    $item = $rfq->items()->create(['species_text' => 'Iroko', 'quantity' => 10, 'unit' => 'm3']);
    $routing = RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(), 'currency' => 'USD',
    ]);
    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(), 'rfq_item_id' => $item->getKey(),
        'quantity' => 10, 'unit' => 'm3', 'unit_price' => 100, 'line_total' => Quote::lineTotal(10, 100),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    app(ChatCommerceService::class)->issueQuotation($c, $quote->refresh(), $staff);
    app(ChatCommerceService::class)->acceptQuotation($c->refresh(), $quote->refresh(), $buyer);

    $fresh = Order::where('quote_id', $quote->getKey())->firstOrFail();
    expect($fresh->status)->toBe(OrderStatus::Awarded);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$fresh->reference_code.'/reorder')
        ->assertOk()
        ->assertJsonPath('data.eligible', false);

    expect($response->json('data.reason'))->toBe('You can reorder once this order has been delivered.');
});

it('surfaces an already-open reorder as in_progress with its rfq reference', function () {
    [$c, $buyer, , , $order] = reapiScene();

    $card = app(\App\Services\ReorderService::class)->request($c, $order, $buyer);
    $rfq = $card->related;

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertOk()
        ->assertJsonPath('data.eligible', true)
        ->assertJsonPath('data.in_progress', true)
        ->assertJsonPath('data.existing_rfq_reference', $rfq->reference_code);

    // Still eligible per canReorder(), so the client still gets lines to show
    // alongside the "already in progress" state.
    expect($response->json('data.lines'))->not->toBeNull();
});

it("404s another buyer's order on the eligibility check", function () {
    [, , , , $order] = reapiScene();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertNotFound();
});

it('requires buyer auth for the eligibility check', function () {
    [, , , , $order] = reapiScene();

    $this->getJson('/api/v1/orders/'.$order->reference_code.'/reorder')->assertUnauthorized();
});

it('rejects a supplier account on the eligibility check', function () {
    [, , , , $order] = reapiScene();

    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertForbidden();
});

/* ================================================================= STORE */

it('raises a reorder request that lands a reorder_request card in the conversation', function () {
    [$c, $buyer, , , $order] = reapiScene();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder', [
            'notes' => 'Same as last time please',
        ])
        ->assertCreated()
        ->assertJsonPath('data.message.kind', MessageType::ReorderRequest->value)
        ->assertJsonPath('data.conversation.id', $c->getKey());

    $messageId = $response->json('data.message.id');

    $card = $c->messages()->whereKey($messageId)->firstOrFail();
    expect($card->type)->toBe(MessageType::ReorderRequest)
        ->and($card->related)->toBeInstanceOf(Rfq::class)
        ->and($card->related->reorder_of_order_id)->toBe($order->getKey());
});

it('is idempotent: a second POST while one is open returns the same card, not a duplicate', function () {
    [$c, $buyer, , , $order] = reapiScene();

    $first = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertCreated();

    $second = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertCreated();

    expect($second->json('data.message.id'))->toBe($first->json('data.message.id'))
        ->and(Rfq::where('reorder_of_order_id', $order->getKey())->count())->toBe(1)
        ->and($c->messages()->where('type', MessageType::ReorderRequest->value)->count())->toBe(1);
});

it('accepts an adjusted quantity and prefills the rest from the previous order', function () {
    [, $buyer, , , $order] = reapiScene();

    $itemId = (int) $order->items->first()->getKey();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder', [
            'quantities' => [$itemId => 80],
        ])
        ->assertCreated();

    $rfq = Rfq::where('reorder_of_order_id', $order->getKey())->firstOrFail();
    expect((float) $rfq->items->first()->quantity)->toBe(80.0);
});

it('ignores any price-shaped field a buyer posts', function () {
    [, $buyer, , , $order] = reapiScene(620.00);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder', [
            'unit_price' => 1,
            'lines' => [['unit_price' => 1]],
        ])
        ->assertCreated();

    $rfq = Rfq::where('reorder_of_order_id', $order->getKey())->firstOrFail();
    foreach ($rfq->items as $item) {
        expect($item->getAttributes())->not->toHaveKey('unit_price');
    }
});

it('refuses to reorder a cancelled order with a real domain reason', function () {
    [, $buyer, , , $order] = reapiScene();

    \Illuminate\Support\Facades\DB::table('orders')->where('id', $order->getKey())
        ->update(['status' => OrderStatus::Cancelled->value, 'cancelled_at' => now()]);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'reorder_not_eligible');

    expect(Rfq::where('reorder_of_order_id', $order->getKey())->exists())->toBeFalse();
});

it("404s another buyer's order on the write", function () {
    [, , , , $order] = reapiScene();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertNotFound();
});

it('requires buyer auth to raise a reorder', function () {
    [, , , , $order] = reapiScene();

    $this->postJson('/api/v1/orders/'.$order->reference_code.'/reorder')->assertUnauthorized();
});

it('rejects a supplier account on the write', function () {
    [, , , , $order] = reapiScene();

    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $this->actingAs($supplierUser, 'sanctum')
        ->postJson('/api/v1/orders/'.$order->reference_code.'/reorder')
        ->assertForbidden();
});
