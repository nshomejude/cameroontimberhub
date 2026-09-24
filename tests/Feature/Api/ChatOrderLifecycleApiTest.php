<?php

use App\Enums\CompanyUserRole;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\ChatCommerceService;
use App\Services\LeadFlowService;
use App\Services\MessagingService;
use App\Services\RfqTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    Storage::fake('documents');
});

/* ------------------------------------------------------------------ helpers */
/* Prefixed `col` (Chat Order Lifecycle) so these never collide with
   ReorderApiTest's `reapi*` or ChatCommerceApiTest's `chatApi*` helpers. */

/**
 * A freshly-awarded order (status `awarded`), its conversation, and both
 * parties, via the real award path.
 *
 * @return array{0: Conversation, 1: User, 2: Company, 3: User, 4: Order}
 */
function colScene(float $unitPrice = 620.00): array
{
    $plan = Plan::factory()->create(['features' => ['leads_receive' => true]]);
    $company = Company::factory()->publiclyVisible()->create(['plan_id' => $plan->id]);
    $staff = User::factory()->create(['email' => 'colstaff'.uniqid().'@example.com']);
    $company->users()->attach($staff, ['role' => CompanyUserRole::Owner->value, 'is_primary' => true]);

    $buyer = User::factory()->create([
        'email' => 'colbuyer'.uniqid().'@example.com',
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

    return [$conversation->refresh(), $buyer, $company, $staff, $order->refresh()];
}

function colAdmin(): User
{
    $admin = User::factory()->create(['email' => 'coladmin'.uniqid().'@example.com']);
    $admin->assignRole('admin');

    return $admin;
}

/** Admin triage: approve the reorder RFQ and route it to the supplier. */
function colTriage(Rfq $rfq, Company $company): Rfq
{
    $admin = colAdmin();
    $triage = app(RfqTriageService::class);

    $triage->approve($rfq, $admin);
    $triage->route($rfq->refresh(), [$company->getKey()], $admin, app(LeadFlowService::class));

    return $rfq->refresh();
}

/* ============================================================== LIFECYCLE */

it('runs the full order lifecycle chain through the API, each step gated to the correct side', function () {
    [$conversation, $buyer, , $staff, $order] = colScene();

    $base = "/api/v1/conversations/{$conversation->id}/orders/{$order->id}";

    // Buyer may not confirm — supplier-only.
    $this->actingAs($buyer, 'sanctum')->postJson("{$base}/confirm")->assertForbidden();

    $this->actingAs($staff, 'sanctum')->postJson("{$base}/confirm")
        ->assertCreated()
        ->assertJsonPath('data.kind', 'shipment_update');
    expect($order->refresh()->status)->toBe(OrderStatus::Confirmed);

    $this->actingAs($staff, 'sanctum')->postJson("{$base}/production")->assertCreated();
    expect($order->refresh()->status)->toBe(OrderStatus::InProduction);

    $this->actingAs($staff, 'sanctum')->postJson("{$base}/ship", ['carrier' => 'Maersk'])->assertCreated();
    expect($order->refresh()->status)->toBe(OrderStatus::Shipped);

    $this->actingAs($staff, 'sanctum')->postJson("{$base}/tracking", ['tracking_number' => 'TRK-1'])->assertCreated();

    // Buyer may not deliver — supplier-only.
    $this->actingAs($buyer, 'sanctum')->postJson("{$base}/deliver")->assertForbidden();

    $this->actingAs($staff, 'sanctum')->postJson("{$base}/deliver")
        ->assertCreated()
        ->assertJsonPath('data.kind', 'order_delivered');
    expect($order->refresh()->status)->toBe(OrderStatus::Delivered);

    // Supplier may not complete — buyer-only.
    $this->actingAs($staff, 'sanctum')->postJson("{$base}/complete")->assertForbidden();

    $this->actingAs($buyer, 'sanctum')->postJson("{$base}/complete")
        ->assertCreated()
        ->assertJsonPath('data.kind', 'transaction_completed');
    expect($order->refresh()->status)->toBe(OrderStatus::Completed);

    // Supplier may not review — buyer-only.
    $this->actingAs($staff, 'sanctum')
        ->postJson("{$base}/review", ['rating' => 5])
        ->assertForbidden();

    $this->actingAs($buyer, 'sanctum')
        ->postJson("{$base}/review", ['rating' => 5, 'body' => 'Great supplier.'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'company_review');
});

it('lets the supplier issue a proforma invoice card and the buyer read the structured proforma sheet', function () {
    [$conversation, $buyer, , $staff, $order] = colScene();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/proforma")
        ->assertCreated()
        ->assertJsonPath('data.kind', 'proforma_invoice');

    $sheet = $this->actingAs($buyer, 'sanctum')
        ->getJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/proforma")
        ->assertOk();

    expect($sheet->json('data.reference_code'))->toBe($order->reference_code)
        ->and($sheet->json('data.items'))->toHaveCount(1);
});

it('lets the supplier request and record payment, and blocks the buyer from recording one', function () {
    [$conversation, $buyer, , $staff, $order] = colScene();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/payment-request", ['reference' => 'INV-1'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'payment_request');

    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/payment-record", ['amount' => 100])
        ->assertForbidden();

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/payment-record", ['amount' => 100, 'method' => 'Bank transfer'])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'payment_confirmed');
});

it('409s an illegal transition (shipping an order that was never confirmed)', function () {
    [$conversation, , , $staff, $order] = colScene();

    $response = $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/ship")
        ->assertStatus(409);

    expect($response->json('error.code'))->toBe('order_transition_not_allowed');
    expect($order->refresh()->status)->toBe(OrderStatus::Awarded);
});

/* ================================================================ DOCUMENTS */

it('lets the supplier attach a document, which appears as an order_documents card, and rejects an oversized file', function () {
    [$conversation, , , $staff, $order] = colScene();

    $file = UploadedFile::fake()->create('packing-list.pdf', 500, 'application/pdf');

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/documents", [
            'documents' => [$file],
            'kind' => 'packing_list',
        ])
        ->assertCreated()
        ->assertJsonPath('data.kind', 'order_documents');

    $oversized = UploadedFile::fake()->create('too-big.pdf', 20000, 'application/pdf');

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/documents", [
            'documents' => [$oversized],
        ])
        ->assertStatus(422);

    $wrongType = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');

    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/documents", [
            'documents' => [$wrongType],
        ])
        ->assertStatus(422);
});

/* ================================================================= REORDER */

it('lets the supplier price a reorder request from the chat, issuing a fresh quotation', function () {
    [$conversation, $buyer, $company, $staff, $order] = colScene(620.00);

    app(App\Services\OrderLifecycleService::class)->confirm($conversation, $order->refresh(), $staff);
    app(App\Services\OrderLifecycleService::class)->startProduction($conversation, $order->refresh(), $staff);
    app(App\Services\OrderLifecycleService::class)->ship($conversation, $order->refresh(), $staff);
    app(App\Services\OrderLifecycleService::class)->deliver($conversation, $order->refresh(), $staff);
    app(App\Services\OrderLifecycleService::class)->complete($conversation, $order->refresh(), $buyer);

    $card = app(App\Services\ReorderService::class)->request($conversation, $order->refresh(), $buyer);
    $rfq = $card->related;

    // Not routed yet: supplier pricing must be refused.
    $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/reorders/{$rfq->id}/quote", [
            'lines' => [$rfq->items->first()->id => ['unit_price' => 650]],
        ])
        ->assertStatus(409);

    colTriage($rfq, $company);

    $response = $this->actingAs($staff, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/reorders/{$rfq->id}/quote", [
            'lines' => [$rfq->fresh()->items->first()->id => ['unit_price' => 650]],
        ])
        ->assertCreated();

    expect($response->json('data.kind'))->toBe('quotation');

    // Buyer may not price a reorder — supplier-only.
    $this->actingAs($buyer, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/reorders/{$rfq->id}/quote", [
            'lines' => [$rfq->fresh()->items->first()->id => ['unit_price' => 650]],
        ])
        ->assertForbidden();
});

/* ============================================================== ENUMERATION */

it('401s a guest on every order-lifecycle endpoint', function () {
    [$conversation, , , , $order] = colScene();

    $this->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/confirm")->assertUnauthorized();
    $this->getJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/proforma")->assertUnauthorized();
});

it('404s every order-lifecycle endpoint for a non-participant', function () {
    [$conversation, , , , $order] = colScene();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversation->id}/orders/{$order->id}/confirm")
        ->assertNotFound();
});
