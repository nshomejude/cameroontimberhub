<?php

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\MessagingService;
use App\Services\OrderService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/*
 * Backend compatibility with the RELEASED mobile app build: conversation
 * start aliases, chat attachables, supply-chain relationships.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

function compatSupplier(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($user, ['role' => 'owner']);

    return [$user, $company];
}

/** A real awarded order with $company for $buyer, via QuoteService::accept(). */
function compatOrder(Company $company, User $buyer): Order
{
    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey()]);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 10, 'unit' => 'm3']);
    $routing = RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $quote = Quote::factory()->submitted()->create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'rfq_company_id' => $routing->getKey()]);
    $quote->items()->create(['description' => 'Sapele', 'quantity' => 10, 'unit' => 'm3', 'unit_price' => 100, 'line_total' => Quote::lineTotal(10, 100)]);
    $quote->load('items')->recalculateTotals()->save();

    $accepted = app(QuoteService::class)->accept($quote->fresh());
    $order = Order::where('quote_id', $accepted->getKey())->firstOrFail();
    $order->forceFill(['user_id' => $buyer->getKey()])->save();

    return $order->fresh();
}

function compatComplete(Order $order): void
{
    $svc = app(OrderService::class);
    $svc->confirm($order);
    $svc->startProduction($order);
    $svc->ship($order);
    $svc->deliver($order);
    $svc->complete($order);
}

/* ------------------------------------------------- POST /conversations */

it('accepts the released app payload {company_slug, product_slug} without a body', function () {
    $buyer = User::factory()->create();
    [, $company] = compatSupplier();
    $product = Product::factory()->create(['company_id' => $company->getKey()]);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company_slug' => $company->slug, 'product_slug' => $product->slug])
        ->assertCreated();

    $conversation = Conversation::findOrFail($response->json('data.id'));
    expect($conversation->company_id)->toBe($company->getKey())
        ->and($conversation->product_id)->toBe($product->getKey())
        ->and($conversation->messages()->where('type', \App\Enums\MessageType::Text->value)->count())->toBe(0);

    // Second tap reuses the thread.
    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company_slug' => $company->slug, 'product_slug' => $product->slug])
        ->assertOk()->assertJsonPath('data.id', $conversation->id);
});

it('422s a product_slug that belongs to another company', function () {
    $buyer = User::factory()->create();
    [, $company] = compatSupplier();
    $foreign = Product::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company_slug' => $company->slug, 'product_slug' => $foreign->slug])
        ->assertUnprocessable();
});

/* ------------------------------------------------------- attachables */

it('lists the buyer orders and rfqs with the thread company only', function () {
    $buyer = User::factory()->create();
    [$supplierUser, $company] = compatSupplier();
    [, $other] = compatSupplier();
    $mine = compatOrder($company, $buyer);
    compatOrder($other, $buyer);
    $conversation = app(MessagingService::class)->start($buyer, $company);

    $orders = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/attachables?type=order")
        ->assertOk()->json('data');

    expect($orders)->toHaveCount(1)
        ->and($orders[0])->toMatchArray(['type' => 'order', 'reference' => $mine->reference_code, 'status' => 'awarded'])
        ->and($orders[0])->toHaveKeys(['id', 'title', 'status_label', 'total_amount', 'currency', 'created_at']);

    $rfqs = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/attachables?type=rfqs")
        ->assertOk()->json('data');
    expect(collect($rfqs)->pluck('reference')->all())->toBe([$mine->rfq->reference_code]);

    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/attachables?type=shipment")
        ->assertOk()->assertExactJson(['data' => []]);

    // Supplier side sees that buyer's order and quote.
    $quotes = $this->actingAs($supplierUser, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/attachables?type=quote")
        ->assertOk()->json('data');
    expect($quotes)->toHaveCount(1)->and($quotes[0]['type'])->toBe('quote');

    $this->actingAs($supplierUser, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/attachables?type=orders")
        ->assertOk()->assertJsonPath('data.0.reference', $mine->reference_code);
});

it('404s attachables for a non-participant and 422s an unknown type', function () {
    $buyer = User::factory()->create();
    [, $company] = compatSupplier();
    $conversation = app(MessagingService::class)->start($buyer, $company);

    $this->actingAs(User::factory()->create(), 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/attachables?type=order")->assertNotFound();
    $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/attachables?type=invoice")->assertUnprocessable();
});

/* --------------------------------------------- supply-chain relationships */

it('derives active relationships from completed orders only', function () {
    $buyerCompany = Company::factory()->publiclyVisible()->create();
    $buyer = User::factory()->create();
    $buyerCompany->users()->attach($buyer, ['role' => 'owner']);
    [$supplierUser, $company] = compatSupplier();
    [, $pendingSupplier] = compatSupplier();

    compatComplete(compatOrder($company, $buyer));
    compatOrder($pendingSupplier, $buyer); // not completed: no relationship

    $rows = $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/supply-chain/relationships?status=active')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['relationship' => 'supplier', 'status' => 'active', 'orders_count' => 1, 'actions' => []])
        ->and($rows[0]['company']['slug'])->toBe($company->slug);

    $rows = $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/supply-chain/relationships')->assertOk()->json('data');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['relationship'])->toBe('customer')
        ->and($rows[0]['company']['id'])->toBe($buyerCompany->id);

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/supply-chain/relationships?status=pending')
        ->assertOk()->assertExactJson(['data' => []]);
});
