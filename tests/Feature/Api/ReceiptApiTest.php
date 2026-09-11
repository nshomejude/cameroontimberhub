<?php

use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Receipt;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * A real awarded Order for $buyer (mirrors OrderApiTest's own `apiOrder()`
 * helper, so orders are built the same way across the API test suite).
 */
function apiReceiptOrder(?User $buyer = null): Order
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

/**
 * Awarding a quote (via `apiReceiptOrder()`) already auto-issues a live
 * receipt on the order (`QuoteService::accept()` "issue receipt" step, see
 * its docblock) — `receipts_live_per_order_unique` forbids a second live
 * one, so tests use that auto-issued receipt rather than minting another
 * with the factory.
 */
function apiIssuedReceipt(Order $order): Receipt
{
    return $order->receipt()->firstOrFail();
}

/* -------------------------------------------------------------- listing */

it('lists only the buyer own live receipts, newest first', function () {
    $buyer = User::factory()->create();
    $olderOrder = apiReceiptOrder($buyer);
    $older = apiIssuedReceipt($olderOrder);
    $older->forceFill(['issued_at' => now()->subDay()])->saveQuietly();

    $newerOrder = apiReceiptOrder($buyer);
    $newer = apiIssuedReceipt($newerOrder);
    $newer->forceFill(['issued_at' => now()])->saveQuietly();

    // Someone else's receipt must never appear.
    apiReceiptOrder(User::factory()->create());

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/receipts')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    expect($response->json('data.0.receipt_number'))->toBe($newer->receipt_number)
        ->and($response->json('data.1.receipt_number'))->toBe($older->receipt_number);
});

it('excludes a voided receipt from the list', function () {
    $buyer = User::factory()->create();
    $order = apiReceiptOrder($buyer);
    $live = apiIssuedReceipt($order);

    $voidedOrder = apiReceiptOrder($buyer);
    apiIssuedReceipt($voidedOrder)->forceFill(['voided_at' => now()])->saveQuietly();

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/receipts')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.receipt_number'))->toBe($live->receipt_number);
});

it('returns an empty list for a buyer with zero receipts', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/receipts')
        ->assertOk()
        ->assertJson(fn ($json) => $json->has('data', 0)->etc());
});

it('requires buyer auth to list receipts', function () {
    $this->getJson('/api/v1/receipts')->assertUnauthorized();
});

/* ----------------------------------------------------------------- show */

it('shows a single live receipt the buyer owns, with the correct shape', function () {
    $buyer = User::factory()->create();
    $order = apiReceiptOrder($buyer);
    $receipt = apiIssuedReceipt($order);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/receipts/'.$receipt->receipt_number)
        ->assertOk()
        ->assertJsonPath('data.receipt_number', $receipt->receipt_number)
        ->assertJsonPath('data.amount', (string) $receipt->amount)
        ->assertJsonPath('data.currency', $receipt->currency->value)
        ->assertJsonPath('data.verification_status', 'AUTHENTIC')
        ->assertJsonPath('data.verification_url', $receipt->verificationUrl())
        ->assertJsonPath('data.order.reference', $order->reference_code)
        ->assertJsonPath('data.order.supplier_name', $order->supplier_name);

    expect($response->json('data.issued_at'))->not->toBeNull();
});

it("404s someone else's receipt number", function () {
    $buyer = User::factory()->create();
    $theirsOrder = apiReceiptOrder(User::factory()->create());
    $theirs = apiIssuedReceipt($theirsOrder);

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/receipts/'.$theirs->receipt_number)
        ->assertNotFound();
});

it("404s a voided receipt's number, even for its own buyer", function () {
    $buyer = User::factory()->create();
    $order = apiReceiptOrder($buyer);
    $voided = apiIssuedReceipt($order);
    $voided->forceFill(['voided_at' => now()])->saveQuietly();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/receipts/'.$voided->receipt_number)
        ->assertNotFound();
});

it('404s an unknown receipt number', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/receipts/RCT-2099-NOTREAL')
        ->assertNotFound();
});

it('requires buyer auth to view a single receipt', function () {
    $order = apiReceiptOrder(User::factory()->create());
    $receipt = apiIssuedReceipt($order);

    $this->getJson('/api/v1/receipts/'.$receipt->receipt_number)->assertUnauthorized();
});

/* --------------------------------------------------------------- gating */

it('rejects a supplier account on the buyer-only receipts endpoints', function () {
    $supplierUser = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($supplierUser);

    $this->actingAs($supplierUser, 'sanctum')->getJson('/api/v1/receipts')->assertForbidden();
});
