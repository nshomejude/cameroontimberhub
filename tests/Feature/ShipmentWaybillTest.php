<?php

use App\Models\Company;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Species;
use App\Services\QuoteService;
use App\Services\ShipmentService;
use App\Services\ShipmentWaybillQrCodeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/* Named distinctly from OrderTest's/QuoteTest's helpers — Pest loads every
   test file into the same process, so a duplicate global function name is a
   fatal error. */

/** A real, awarded order (species-only line, no catalogue Product). */
function shipmentOrder(): Order
{
    $company = Company::factory()->publiclyVisible()->create();
    $rfq = Rfq::factory()->approved()->create();
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
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

    return Order::where('quote_id', $quote->getKey())->firstOrFail();
}

/** A real, awarded order whose line matches a live catalogue Product (for cargoProductIds()). */
function shipmentOrderWithProduct(): array
{
    $species = Species::factory()->create();
    $company = Company::factory()->publiclyVisible()->create();
    $product = Product::factory()->create(['company_id' => $company->getKey(), 'species_id' => $species->getKey()]);

    $rfq = Rfq::factory()->approved()->create();
    $rfq->items()->create([
        'species_id' => $species->getKey(), 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
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
    ]);

    QuoteItem::factory()->create([
        'quote_id' => $quote->getKey(),
        'species_id' => $species->getKey(),
        'description' => 'Sawn timber',
        'quantity' => 100,
        'unit_price' => 185.00,
        'line_total' => Quote::lineTotal(100, 185.00),
    ]);

    $quote->load('items')->recalculateTotals()->save();

    app(QuoteService::class)->accept($quote);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    return [$order, $product];
}

test('ShipmentService creates a Shipment from an Order with no fleet data required', function () {
    $order = shipmentOrder();

    $shipment = app(ShipmentService::class)->createFromOrder($order);

    expect($shipment->exists)->toBeTrue()
        ->and($shipment->order_id)->toBe($order->getKey())
        ->and($shipment->vehicle_id)->toBeNull()
        ->and($shipment->driver_id)->toBeNull()
        ->and($shipment->waybill_number)->not->toBeEmpty();
});

test('the QR data URI encodes the public waybill URL', function () {
    $order = shipmentOrder();
    $shipment = app(ShipmentService::class)->createFromOrder($order);

    $uri = app(ShipmentWaybillQrCodeService::class)->dataUri($shipment);

    expect($uri)->toStartWith('data:image/svg+xml;base64,');
});

test('the public waybill page lists the cargo product IDs and renders a QR', function () {
    [$order, $product] = shipmentOrderWithProduct();
    $shipment = app(ShipmentService::class)->createFromOrder($order);

    $response = $this->get(route('shipments.waybill.show', $shipment));

    $response->assertOk();
    $response->assertSee($shipment->waybill_number);
    $response->assertSee((string) $product->id);
});
