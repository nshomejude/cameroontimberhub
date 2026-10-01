<?php

use App\Enums\ConversationTopic;
use App\Enums\MessageType;
use App\Enums\OrderDocumentKind;
use App\Enums\OrderStatus;
use App\Enums\RfqType;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Shipment;
use App\Models\User;
use App\Services\MessagingService;
use App\Services\OrderDocumentService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

/*
 * Launch-blocking mobile API gaps: buyer-started conversations, supplier
 * fulfilment by order reference, supplier order documents/shipments,
 * account_type, supplier RFQ type, quote conversation_id.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    Storage::fake('documents');
});

function launchSupplier(): array
{
    $user = User::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $company->users()->attach($user);

    return [$user, $company];
}

/** A real awarded order for $company via QuoteService::accept(), optionally owned by $buyer. */
function launchAwardedOrder(Company $company, ?User $buyer = null): Order
{
    $rfq = Rfq::factory()->approved()->create($buyer ? ['user_id' => $buyer->getKey()] : []);
    $rfq->items()->create(['species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 50, 'unit' => 'm3']);

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
    $quote->items()->create([
        'description' => 'Sapele sawn timber', 'quantity' => 50, 'unit' => 'm3',
        'unit_price' => 185, 'line_total' => Quote::lineTotal(50, 185),
    ]);
    $quote->load('items')->recalculateTotals()->save();

    $accepted = app(QuoteService::class)->accept($quote->fresh());

    return Order::where('quote_id', $accepted->getKey())->firstOrFail();
}

/* ------------------------------------------------- POST /conversations */

it('lets a verified buyer start a conversation with a supplier by slug, posting the first message', function () {
    $buyer = User::factory()->create();
    [, $company] = launchSupplier();

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->slug, 'body' => 'Do you stock Iroko?'])
        ->assertCreated()
        ->assertJsonPath('data.topic', ConversationTopic::General->value);

    $conversation = Conversation::findOrFail($response->json('data.id'));

    expect($conversation->user_id)->toBe($buyer->getKey())
        ->and($conversation->company_id)->toBe($company->getKey())
        ->and($conversation->messages()->where('type', MessageType::Text->value)->where('body', 'Do you stock Iroko?')->exists())->toBeTrue();
});

it('reuses the open conversation (200) and accepts the company id plus a product of that company', function () {
    $buyer = User::factory()->create();
    [, $company] = launchSupplier();
    $product = Product::factory()->create(['company_id' => $company->getKey()]);

    $first = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->getKey(), 'product_id' => $product->getKey(), 'body' => 'Hello'])
        ->assertCreated();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => (string) $company->getKey(), 'body' => 'Following up'])
        ->assertOk()
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect(Conversation::where('user_id', $buyer->getKey())->count())->toBe(1);
});

it('rejects a product from another company and an unknown company with 422', function () {
    $buyer = User::factory()->create();
    [, $company] = launchSupplier();
    $foreign = Product::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->slug, 'product_id' => $foreign->getKey(), 'body' => 'x'])
        ->assertStatus(422);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => 'no-such-company', 'body' => 'x'])
        ->assertStatus(422);
});

it('refuses an unverified buyer a NEW conversation with 403 email_unverified, but lets them reuse an open one', function () {
    $buyer = User::factory()->unverified()->create();
    [, $company] = launchSupplier();

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->slug, 'body' => 'Hi'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'email_unverified');

    expect(Conversation::count())->toBe(0);

    app(MessagingService::class)->start($buyer, $company);

    $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->slug, 'body' => 'Hi again'])
        ->assertOk();
});

it('attaches an order reference only when the buyer owns it with that company', function () {
    $buyer = User::factory()->create();
    [, $company] = launchSupplier();
    $order = launchAwardedOrder($company, $buyer);

    $response = $this->actingAs($buyer, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->slug, 'order' => $order->reference_code, 'body' => 'About my order'])
        ->assertCreated()
        ->assertJsonPath('data.topic', ConversationTopic::Order->value);

    expect(Conversation::find($response->json('data.id'))->order_id)->toBe($order->getKey());

    $stranger = User::factory()->create();
    $this->actingAs($stranger, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $company->slug, 'order' => $order->reference_code, 'body' => 'x'])
        ->assertStatus(422);
});

it('does not let a supplier start a conversation through this endpoint', function () {
    [$supplier] = launchSupplier();
    [, $other] = launchSupplier();

    $this->actingAs($supplier, 'sanctum')
        ->postJson('/api/v1/conversations', ['company' => $other->slug, 'body' => 'Hi'])
        ->assertForbidden();
});

/* ------------------------------------- supplier fulfilment by reference */

it('confirms, produces, ships and delivers an order with NO conversation via the exporter path', function () {
    [$user, $company] = launchSupplier();
    $order = launchAwardedOrder($company);

    expect($order->conversation)->toBeNull();

    $base = "/api/v1/supplier/orders/{$order->reference_code}";

    $this->actingAs($user, 'sanctum')->postJson("{$base}/confirm")
        ->assertOk()->assertJsonPath('data.status', OrderStatus::Confirmed->value)->assertJsonPath('data.conversation_id', null);
    $this->actingAs($user, 'sanctum')->postJson("{$base}/production")
        ->assertOk()->assertJsonPath('data.status', OrderStatus::InProduction->value);
    $this->actingAs($user, 'sanctum')->postJson("{$base}/ship", ['carrier' => 'Maersk', 'tracking_number' => 'MSK123'])
        ->assertOk()->assertJsonPath('data.status', OrderStatus::Shipped->value);
    $this->actingAs($user, 'sanctum')->postJson("{$base}/tracking", ['vessel_name' => 'Northern Star'])
        ->assertOk();

    $this->actingAs($user, 'sanctum')->post("{$base}/deliver", [
        'received_by' => 'Port agent',
        'proof' => [UploadedFile::fake()->create('pod.pdf', 100, 'application/pdf')],
    ], ['Accept' => 'application/json'])
        ->assertOk()->assertJsonPath('data.status', OrderStatus::Delivered->value);

    $order->refresh();
    expect($order->carrier)->toBe('Maersk')
        ->and($order->vessel_name)->toBe('Northern Star')
        ->and($order->delivered_to_name)->toBe('Port agent')
        ->and($order->documents()->where('kind', OrderDocumentKind::ProofOfDelivery->value)->count())->toBe(1);
});

it('routes fulfilment through the order conversation when one exists, posting the chat card', function () {
    [$user, $company] = launchSupplier();
    $buyer = User::factory()->create();
    $order = launchAwardedOrder($company, $buyer);
    $conversation = app(MessagingService::class)->start($buyer, $company, ConversationTopic::Order, order: $order);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', OrderStatus::Confirmed->value)
        ->assertJsonPath('data.conversation_id', $conversation->getKey());

    expect($conversation->messages()->where('type', MessageType::ShipmentUpdate->value)->exists())->toBeTrue();
});

it('answers an illegal transition with 409 and another company\'s order with 404', function () {
    [$user, $company] = launchSupplier();
    $order = launchAwardedOrder($company);

    $this->actingAs($user, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/deliver")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'order_transition_not_allowed');

    [$stranger] = launchSupplier();
    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/supplier/orders/{$order->reference_code}/confirm")
        ->assertNotFound();

    expect($order->fresh()->status)->toBe(OrderStatus::Awarded);
});

/* ------------------------------------ supplier documents & shipments */

it('lists and downloads the supplier order documents, scoped to the supplying company', function () {
    [$user, $company] = launchSupplier();
    $order = launchAwardedOrder($company);
    $document = app(OrderDocumentService::class)->store(
        $order,
        UploadedFile::fake()->create('invoice.pdf', 120, 'application/pdf'),
        OrderDocumentKind::CommercialInvoice,
        $user,
    );

    $base = "/api/v1/supplier/orders/{$order->reference_code}";

    $this->actingAs($user, 'sanctum')->getJson("{$base}/documents")
        ->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user, 'sanctum')->get("{$base}/documents/{$document->getKey()}/download")
        ->assertOk();

    [$stranger] = launchSupplier();
    $this->actingAs($stranger, 'sanctum')->getJson("{$base}/documents")->assertNotFound();
    $this->actingAs($stranger, 'sanctum')->get("{$base}/documents/{$document->getKey()}/download")->assertNotFound();

    // A document of another order 404s even through the caller's own order.
    $otherOrder = launchAwardedOrder($company);
    $this->actingAs($user, 'sanctum')
        ->get("/api/v1/supplier/orders/{$otherOrder->reference_code}/documents/{$document->getKey()}/download")
        ->assertNotFound();
});

it('lists the supplier order shipments, scoped to the supplying company', function () {
    [$user, $company] = launchSupplier();
    $order = launchAwardedOrder($company);
    Shipment::factory()->create(['order_id' => $order->getKey()]);

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/v1/supplier/orders/{$order->reference_code}/shipments")
        ->assertOk()->assertJsonCount(1, 'data');

    [$stranger] = launchSupplier();
    $this->actingAs($stranger, 'sanctum')
        ->getJson("/api/v1/supplier/orders/{$order->reference_code}/shipments")
        ->assertNotFound();
});

/* ------------------------------------------------------ account_type */

it('exposes account_type on /auth/me and the dashboard', function (string $roleName, string $expected) {
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate($roleName, 'web'));

    if (in_array($roleName, ['processor', 'logistics_partner'], true)) {
        Company::factory()->publiclyVisible()->create()->users()->attach($user);
    }

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()->assertJsonPath('data.account_type', $expected);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()->assertJsonPath('data.account_type', $expected);
})->with([
    ['buyer', 'buyer'],
    ['carbon_buyer', 'carbon_buyer'],
    ['processor', 'processor'],
    ['logistics_partner', 'logistics_partner'],
]);

it('falls back to the company type for a company member without an account role, and returns company.type on the dashboard', function () {
    [$user, $company] = launchSupplier();

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.account_type', match ($company->type?->value) {
            'processor', 'artisan', 'carbon_developer' => $company->type->value,
            'logistics' => 'logistics_partner',
            default => 'supplier',
        })
        ->assertJsonPath('data.company.type', $company->type?->value);

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/dashboard')
        ->assertOk()
        ->assertJsonPath('data.company.id', $company->getKey())
        ->assertJsonPath('data.company.type', $company->type?->value);
});

/* ------------------------------------------------- supplier RFQ type */

it('exposes the RFQ type and filters the supplier RFQ inbox by it', function () {
    [$user, $company] = launchSupplier();

    foreach ([RfqType::Export, RfqType::DomesticManufacturing] as $type) {
        $rfq = Rfq::factory()->approved()->create(['type' => $type]);
        RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    }

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfqs?type=domestic_manufacturing')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.type', 'domestic_manufacturing');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfqs')
        ->assertOk()->assertJsonCount(2, 'data');

    $this->actingAs($user, 'sanctum')->getJson('/api/v1/supplier/rfqs?type=nonsense')
        ->assertStatus(422);
});

/* ------------------------------------------- quote conversation_id */

it('reports the conversation a quote was shared into, and null otherwise', function () {
    [$supplier, $company] = launchSupplier();
    $buyer = User::factory()->create();

    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey()]);
    $routing = RfqCompany::create(['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'status' => 'sent', 'routed_at' => now()]);
    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey(), 'rfq_company_id' => $routing->getKey(),
    ]);

    $this->actingAs($supplier, 'sanctum')->getJson("/api/v1/supplier/quotes/{$quote->reference_code}")
        ->assertOk()->assertJsonPath('data.conversation_id', null);

    $conversation = app(MessagingService::class)->start($buyer, $company, ConversationTopic::Rfq);
    app(MessagingService::class)->postQuotation($conversation, $supplier, $quote);

    $this->actingAs($supplier, 'sanctum')->getJson("/api/v1/supplier/quotes/{$quote->reference_code}")
        ->assertOk()->assertJsonPath('data.conversation_id', $conversation->getKey());

    $this->actingAs($buyer, 'sanctum')->getJson("/api/v1/rfqs/{$rfq->reference_code}/quotes")
        ->assertOk()->assertJsonPath('data.0.conversation_id', $conversation->getKey());
});
