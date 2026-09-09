<?php

use App\Enums\LotEventType;
use App\Enums\TrackingCheckpointStatus;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\CheckpointUpdate;
use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Shipment;
use App\Models\TimberLot;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Mail;

/* ------------------------------------------------------------------ helpers */
/* Named distinctly from other Shipment-test helper functions (e.g.
   shipmentTestOrder() in ShipmentLotLinkingTest) — Pest loads every test
   file into the same process, so a duplicate global function name is fatal. */

function logisticsCheckpointTestOrder(): Order
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

function logisticsCheckpointTestShipment(): Shipment
{
    return Shipment::factory()->create(['order_id' => logisticsCheckpointTestOrder()->getKey()]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

it('shows the checkpoint recording form for a valid waybill number', function () {
    $shipment = logisticsCheckpointTestShipment();

    $response = $this->get("/logistics/shipments/{$shipment->waybill_number}/checkpoint");

    $response->assertOk();
    $response->assertSee($shipment->waybill_number);
});

it('404s for an unknown/invalid waybill number', function () {
    $response = $this->get('/logistics/shipments/NOT-A-REAL-WAYBILL/checkpoint');

    $response->assertNotFound();

    $postResponse = $this->postJson('/logistics/shipments/NOT-A-REAL-WAYBILL/checkpoint', [
        'status' => TrackingCheckpointStatus::Dispatched->value,
    ]);

    $postResponse->assertNotFound();
});

it('creates a real CheckpointUpdate when submitted online through the waybill-token route', function () {
    $shipment = logisticsCheckpointTestShipment();

    $response = $this->postJson("/logistics/shipments/{$shipment->waybill_number}/checkpoint", [
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port, Gate 3',
        'notes' => 'Left the warehouse on schedule.',
        'occurred_at' => now()->toIso8601String(),
    ]);

    $response->assertCreated();
    $response->assertJson(['saved' => true]);

    $checkpoint = CheckpointUpdate::forTrackable($shipment)->firstOrFail();

    expect($checkpoint->status)->toBe(TrackingCheckpointStatus::Dispatched)
        ->and($checkpoint->location)->toBe('Douala Port, Gate 3')
        ->and($checkpoint->tracking_token)->not->toBeNull();
});

it('fires the existing ShipmentObserver -> TimberLot event wiring for checkpoints created via this endpoint', function () {
    $shipment = logisticsCheckpointTestShipment();
    $lot = TimberLot::factory()->create();
    $shipment->timberLots()->attach($lot->getKey());

    $response = $this->postJson("/logistics/shipments/{$shipment->waybill_number}/checkpoint", [
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
    ]);

    $response->assertCreated();

    // The lot-event side effect moved from synchronous (inline in
    // ShipmentObserver) to async via the transactional Outbox (arch plan
    // Task 0.2) — it's queued as an outbox_events row and only actually
    // recorded once RelayOutboxEventsJob relays it. Run one relay tick
    // synchronously here to observe the eventual, not immediate, effect.
    app(RelayOutboxEventsJob::class)->handle();

    expect(
        $lot->lotEvents()
            ->where('event_type', LotEventType::TransportDispatched->value)
            ->where('location', 'Douala Port')
            ->exists()
    )->toBeTrue();
});

it('reuses the same tracking token across multiple checkpoints for the same shipment', function () {
    $shipment = logisticsCheckpointTestShipment();

    $this->postJson("/logistics/shipments/{$shipment->waybill_number}/checkpoint", [
        'status' => TrackingCheckpointStatus::Dispatched->value,
    ])->assertCreated();

    $this->postJson("/logistics/shipments/{$shipment->waybill_number}/checkpoint", [
        'status' => TrackingCheckpointStatus::InTransit->value,
    ])->assertCreated();

    $tokens = CheckpointUpdate::forTrackable($shipment)->pluck('tracking_token')->unique();

    expect($tokens)->toHaveCount(1);
});

it('records an out-of-order sync without regressing the shipment current status display', function () {
    $shipment = logisticsCheckpointTestShipment();

    // "Delivered" is recorded first (as far as the server is concerned) —
    // simulating a checkpoint that reached the server promptly.
    $delivered = $this->postJson("/logistics/shipments/{$shipment->waybill_number}/checkpoint", [
        'status' => TrackingCheckpointStatus::Delivered->value,
        'location' => 'Le Havre',
        'occurred_at' => now()->toIso8601String(),
    ]);
    $delivered->assertCreated();

    // An earlier "dispatched" checkpoint, queued offline hours before, only
    // syncs to the server now — after "delivered" already arrived. Its
    // client-reported occurred_at is well before the delivered one's.
    $dispatched = $this->postJson("/logistics/shipments/{$shipment->waybill_number}/checkpoint", [
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
        'occurred_at' => now()->subHours(6)->toIso8601String(),
    ]);
    $dispatched->assertCreated();

    // Both checkpoints are recorded — nothing is silently dropped, the full
    // audit trail is intact.
    expect(CheckpointUpdate::forTrackable($shipment)->count())->toBe(2);

    // The dispatched row really was persisted with its true (backdated)
    // client-reported occurred_at, not "now".
    $dispatchedRow = CheckpointUpdate::forTrackable($shipment)
        ->where('status', TrackingCheckpointStatus::Dispatched->value)
        ->firstOrFail();
    expect($dispatchedRow->occurred_at->lt(now()->subHours(5)))->toBeTrue();

    // But the shipment's displayed *current* status is still "Delivered" —
    // the late-arriving, chronologically-earlier "dispatched" sync did not
    // regress it.
    $shipment->refresh();
    expect($shipment->latestCheckpoint()->status)->toBe(TrackingCheckpointStatus::Delivered);
});

it('validates the status field', function () {
    $shipment = logisticsCheckpointTestShipment();

    $response = $this->postJson("/logistics/shipments/{$shipment->waybill_number}/checkpoint", [
        'status' => 'not-a-real-status',
    ]);

    $response->assertStatus(422);
});
