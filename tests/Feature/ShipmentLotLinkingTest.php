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
use App\Models\User;
use App\Services\QuoteService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/* ------------------------------------------------------------------ helpers */
/* Named distinctly from OrderTest's own helpers — Pest loads every test file
   into the same process, so a duplicate global function name is fatal. */

/** Builds a real Order the only way one can be created — via QuoteService::accept(). */
function shipmentTestOrder(): Order
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

function shipmentTestShipment(): Shipment
{
    return Shipment::factory()->create(['order_id' => shipmentTestOrder()->getKey()]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/**
 * The lot-event side effect is now async (ShipmentCheckpointRecorded is
 * recorded to the outbox by ShipmentObserver, and
 * App\Listeners\RecordLotEventOnShipmentCheckpoint — a queued listener —
 * records the LotEvent once the outbox relay dispatches the event).
 * QUEUE_CONNECTION=sync in phpunit.xml means the queued listener still runs
 * inline the moment the event is dispatched, so running the relay job's
 * handle() synchronously right here reproduces the old request-synchronous
 * behavior for these assertions without weakening them.
 */
function runOutboxRelay(): void
{
    app(RelayOutboxEventsJob::class)->handle();
}

it('links a shipment and a timber lot via the pivot in both directions', function () {
    $shipment = shipmentTestShipment();
    $lot = TimberLot::factory()->create();

    $shipment->timberLots()->attach($lot->getKey(), ['quantity_m3' => 12.5]);

    expect($shipment->timberLots()->first()->id)->toBe($lot->id)
        ->and((float) $shipment->timberLots()->first()->pivot->quantity_m3)->toBe(12.5)
        ->and($lot->shipments()->first()->id)->toBe($shipment->id);
});

it('records a matching LotEvent on every linked lot when a dispatched checkpoint is recorded for the shipment', function () {
    $shipment = shipmentTestShipment();
    $lotOne = TimberLot::factory()->create();
    $lotTwo = TimberLot::factory()->create();
    $shipment->timberLots()->attach([$lotOne->getKey(), $lotTwo->getKey()]);

    CheckpointUpdate::factory()->create([
        'trackable_type' => Shipment::class,
        'trackable_id' => $shipment->getKey(),
        'tracking_token' => str()->random(48),
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
    ]);

    runOutboxRelay();

    expect($lotOne->lotEvents()->where('event_type', LotEventType::TransportDispatched->value)->where('location', 'Douala Port')->exists())->toBeTrue()
        ->and($lotTwo->lotEvents()->where('event_type', LotEventType::TransportDispatched->value)->exists())->toBeTrue();
});

it('records a Delivered LotEvent when a delivered checkpoint is recorded for the shipment', function () {
    $shipment = shipmentTestShipment();
    $lot = TimberLot::factory()->create();
    $shipment->timberLots()->attach($lot->getKey());

    CheckpointUpdate::factory()->create([
        'trackable_type' => Shipment::class,
        'trackable_id' => $shipment->getKey(),
        'tracking_token' => str()->random(48),
        'status' => TrackingCheckpointStatus::Delivered->value,
        'location' => 'Le Havre',
    ]);

    runOutboxRelay();

    expect($lot->lotEvents()->where('event_type', LotEventType::Delivered->value)->exists())->toBeTrue();
});

it('does not error when a checkpoint is recorded for a shipment with no linked lots', function () {
    $shipment = shipmentTestShipment();

    $checkpoint = CheckpointUpdate::factory()->create([
        'trackable_type' => Shipment::class,
        'trackable_id' => $shipment->getKey(),
        'tracking_token' => str()->random(48),
        'status' => TrackingCheckpointStatus::Dispatched->value,
    ]);

    expect($checkpoint->exists)->toBeTrue();
});

it('still records the checkpoint even when the lot-event recording fails inside the observer', function () {
    $shipment = shipmentTestShipment();
    $lot = TimberLot::factory()->create();
    $shipment->timberLots()->attach($lot->getKey());

    Log::shouldReceive('error')->atLeast()->once();
    Log::shouldReceive('info')->zeroOrMoreTimes();
    Log::shouldReceive('debug')->zeroOrMoreTimes();
    Log::shouldReceive('warning')->zeroOrMoreTimes();

    // Simulate an internal failure inside TimberLot::recordEvent() without
    // touching LotEvent.php: a temporary `creating` listener that throws
    // before any SQL runs, so no real Postgres error/transaction-abort is
    // involved (two earlier approaches were unreliable here — pre-inserting
    // a row at "MAX(id)+1" doesn't reliably collide with Postgres bigserial's
    // independent nextval() sequence, and renaming the table via DDL inside
    // Pest's per-test transaction leaves the transaction aborted so even the
    // rename-back in a finally block fails).
    \App\Models\LotEvent::creating(function () {
        throw new \RuntimeException('Simulated lot-event recording failure for test.');
    });

    try {
        $checkpoint = CheckpointUpdate::factory()->create([
            'trackable_type' => Shipment::class,
            'trackable_id' => $shipment->getKey(),
            'tracking_token' => str()->random(48),
            'status' => TrackingCheckpointStatus::Dispatched->value,
        ]);

        // The lot-event recording itself now happens inside the queued
        // listener triggered by the outbox relay, not inline at checkpoint
        // creation — so the LotEvent::creating throw must still be armed
        // while the relay runs.
        runOutboxRelay();
    } finally {
        \Illuminate\Support\Facades\Event::forget('eloquent.creating: '.\App\Models\LotEvent::class);
    }

    // The checkpoint (the "shipment status update") still succeeded despite
    // the lot-event recording failing underneath it.
    expect($checkpoint->exists)->toBeTrue()
        ->and($checkpoint->wasRecentlyCreated)->toBeTrue();
});
