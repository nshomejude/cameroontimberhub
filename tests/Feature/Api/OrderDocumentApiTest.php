<?php

use App\Enums\OrderDocumentKind;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Services\OrderDocumentService;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
    Storage::fake('documents');
});

/**
 * A real Order with a genuine buyer and supplier company, mirroring
 * DisputeApiTest's own `apiDisputeOrder()` helper so orders are built the
 * same way across the API test suite.
 *
 * @return array{0: Order, 1: User, 2: Company}
 */
function apiOrderWithDocuments(): array
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

it('lists the order own buyer documents', function () {
    [$order, $buyer] = apiOrderWithDocuments();

    $file = UploadedFile::fake()->create('bill-of-lading.pdf', 200, 'application/pdf');
    $document = app(OrderDocumentService::class)->store($order, $file, OrderDocumentKind::BillOfLading);

    $response = $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/documents')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0.id'))->toBe($document->id)
        ->and($response->json('data.0.kind'))->toBe('bill_of_lading')
        ->and($response->json('data.0.download_url'))->toContain(
            '/api/v1/orders/'.$order->reference_code.'/documents/'.$document->id.'/download'
        );
});

it('returns an empty array for an order with no documents', function () {
    [$order, $buyer] = apiOrderWithDocuments();

    $this->actingAs($buyer, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/documents')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('404s another buyer order documents rather than confirming it exists', function () {
    [$order] = apiOrderWithDocuments();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/documents')
        ->assertNotFound();
});

it('403s a supplier hitting the buyer-only order documents route', function () {
    [$order, , $supplierCompany] = apiOrderWithDocuments();

    $supplierUser = User::factory()->create();
    $supplierCompany->users()->attach($supplierUser);

    // A supplier is legitimately a participant on this order's messaging
    // thread (OrderLifecycleService::mayAccessDocument() allows them), but
    // /orders/{reference}/... stays inside the buyer-only BuyerApiScope
    // route family for this task — see OrderDocumentController's docblock.
    $this->actingAs($supplierUser, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/documents')
        ->assertForbidden();
});

it('401s a guest on every order document endpoint', function () {
    [$order] = apiOrderWithDocuments();

    $this->getJson('/api/v1/orders/'.$order->reference_code.'/documents')->assertUnauthorized();
    $this->getJson('/api/v1/orders/'.$order->reference_code.'/documents/1/download')->assertUnauthorized();
});

/* ------------------------------------------------------------ download */

it('lets the order buyer download an order document', function () {
    [$order, $buyer] = apiOrderWithDocuments();

    $file = UploadedFile::fake()->create('invoice.pdf', 150, 'application/pdf');
    $document = app(OrderDocumentService::class)->store($order, $file, OrderDocumentKind::CommercialInvoice);

    $this->actingAs($buyer, 'sanctum')
        ->get('/api/v1/orders/'.$order->reference_code.'/documents/'.$document->id.'/download')
        ->assertOk();
});

it('404s an unrelated user downloading an order document', function () {
    [$order] = apiOrderWithDocuments();

    $file = UploadedFile::fake()->create('invoice.pdf', 150, 'application/pdf');
    $document = app(OrderDocumentService::class)->store($order, $file, OrderDocumentKind::CommercialInvoice);

    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->getJson('/api/v1/orders/'.$order->reference_code.'/documents/'.$document->id.'/download')
        ->assertNotFound();
});
