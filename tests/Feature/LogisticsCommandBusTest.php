<?php

use App\Domain\Logistics\Commands\RecordCheckpointCommand;
use App\Domain\Logistics\Commands\RecordLotTransformationCommand;
use App\Domain\Logistics\Events\LotTransformationRecorded;
use App\Enums\TrackingCheckpointStatus;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\LotTransformation;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\Shipment;
use App\Models\TimberLot;
use App\Models\WebhookSubscription;
use App\Services\CheckpointTracker;
use App\Support\Bus\CommandBus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Builds a minimal real Order for a fresh Company so Shipment::factory()
 * (which deliberately has no default order_id — Order has no factory of its
 * own) has a real order_id to attach to, mirroring
 * WebhookDeliveryTest::webhookOrderForCompany()'s pattern.
 */
function logisticsCommandBusTestOrder(): Order
{
    $company = Company::factory()->create();
    $rfq = Rfq::factory()->approved()->create();

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
        'quantity' => 10,
        'unit_price' => 100,
        'line_total' => Quote::lineTotal(10, 100),
    ]);

    return Order::create([
        'quote_id' => $quote->getKey(),
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'user_id' => $rfq->user_id,
        'reference_code' => 'ORD-'.uniqid(),
        'status' => 'awarded',
        'buyer_name' => 'Test buyer',
        'buyer_email' => $rfq->buyer_email,
        'supplier_name' => $company->name,
        'currency' => 'USD',
        'subtotal_amount' => 1000,
        'total_amount' => 1000,
        'payment_status' => 'unpaid',
        'amount_paid' => 0,
        'awarded_at' => now(),
    ]);
}

/**
 * Regression-parity + outbox/webhook coverage for bringing Logistics &
 * Traceability's writes onto the CommandBus/Outbox pattern (architecture
 * plan, Task 2.x: RecordCheckpointCommand / RecordLotTransformationCommand).
 */
it('RecordCheckpointCommand produces the same result as calling CheckpointTracker::record() directly', function () {
    $shipmentA = Shipment::factory()->create(['order_id' => logisticsCommandBusTestOrder()->getKey()]);
    $shipmentB = Shipment::factory()->create(['order_id' => logisticsCommandBusTestOrder()->getKey()]);

    $direct = app(CheckpointTracker::class)->record($shipmentA, [
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
    ]);

    $viaCommand = app(CommandBus::class)->dispatch(new RecordCheckpointCommand($shipmentB, [
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
    ]));

    expect($viaCommand->status)->toBe($direct->status)
        ->and($viaCommand->location)->toBe($direct->location)
        ->and($viaCommand->tracking_token)->not->toBeNull();
});

it('RecordCheckpointCommand still records exactly one ShipmentCheckpointRecorded outbox row (via the existing ShipmentObserver, not duplicated)', function () {
    $shipment = Shipment::factory()->create(['order_id' => logisticsCommandBusTestOrder()->getKey()]);

    app(CommandBus::class)->dispatch(new RecordCheckpointCommand($shipment, [
        'status' => TrackingCheckpointStatus::Dispatched->value,
        'location' => 'Douala Port',
    ]));

    expect(
        OutboxEvent::query()
            ->where('event_type', 'checkpoint.recorded')
            ->where('aggregate_id', (string) $shipment->id)
            ->count()
    )->toBe(1);
});

it('RecordLotTransformationCommand produces the same result as calling LotTransformation::recordFor() directly', function () {
    $processor = Company::factory()->create();
    $logsA = TimberLot::factory()->create();
    $sawnA = TimberLot::factory()->create();
    $logsB = TimberLot::factory()->create();
    $sawnB = TimberLot::factory()->create();

    $direct = LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [['lot' => $logsA, 'quantity' => 100]],
        outputs: [['lot' => $sawnA, 'quantity' => 72]],
    );

    $viaCommand = app(CommandBus::class)->dispatch(new RecordLotTransformationCommand(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [['lot' => $logsB, 'quantity' => 100]],
        outputs: [['lot' => $sawnB, 'quantity' => 72]],
    ));

    expect((float) $viaCommand->input_volume_m3)->toBe((float) $direct->input_volume_m3)
        ->and((float) $viaCommand->output_volume_m3)->toBe((float) $direct->output_volume_m3)
        ->and((float) $viaCommand->loss_volume_m3)->toBe((float) $direct->loss_volume_m3)
        ->and((float) $viaCommand->transformation_ratio)->toBe((float) $direct->transformation_ratio);
});

it('records a LotTransformationRecorded outbox row transactionally with the LotTransformation write', function () {
    $processor = Company::factory()->create();
    $logs = TimberLot::factory()->create();
    $sawn = TimberLot::factory()->create();

    $transformation = app(CommandBus::class)->dispatch(new RecordLotTransformationCommand(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [['lot' => $logs, 'quantity' => 100]],
        outputs: [['lot' => $sawn, 'quantity' => 72]],
    ));

    expect(
        OutboxEvent::query()
            ->where('event_type', 'lot_transformation.recorded')
            ->where('aggregate_id', (string) $transformation->id)
            ->exists()
    )->toBeTrue();
});

it('rolls back the LotTransformationRecorded outbox row together with the state change on failure', function () {
    $processor = Company::factory()->create();
    $logs = TimberLot::factory()->create();
    $sawn = TimberLot::factory()->create();

    try {
        DB::transaction(function () use ($processor, $logs, $sawn) {
            app(CommandBus::class)->dispatch(new RecordLotTransformationCommand(
                processorCompanyId: $processor->id,
                transformationType: 'sawing',
                inputs: [['lot' => $logs, 'quantity' => 100]],
                outputs: [['lot' => $sawn, 'quantity' => 72]],
            ));

            throw new RuntimeException('simulated failure after the command, before commit');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LotTransformation::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->where('event_type', 'lot_transformation.recorded')->count())->toBe(0);
});

it('RelayOutboxEventsJob relays a lot_transformation.recorded row by dispatching LotTransformationRecorded', function () {
    Event::fake([LotTransformationRecorded::class]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'LotTransformation',
        'aggregate_id' => '999',
        'event_type' => 'lot_transformation.recorded',
        'payload' => ['lot_transformation_id' => 999, 'processor_company_id' => 1, 'transformation_type' => 'sawing'],
        'occurred_at' => now(),
        'published_at' => null,
        'attempts' => 0,
        'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(LotTransformationRecorded::class, fn (LotTransformationRecorded $e) => $e->lotTransformationId === 999);
});

it('dispatches a DeliverWebhookJob for lot_transformation.recorded to the input lot owner company', function () {
    Bus::fake();

    $owner = Company::factory()->create();
    $processor = Company::factory()->create();
    $logs = TimberLot::factory()->create(['company_id' => $owner->id]);
    $sawn = TimberLot::factory()->create();

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $owner->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['lot_transformation.recorded'],
        'secret' => 'secret',
        'is_active' => true,
    ]);

    $transformation = LotTransformation::recordFor(
        processorCompanyId: $processor->id,
        transformationType: 'sawing',
        inputs: [['lot' => $logs, 'quantity' => 100]],
        outputs: [['lot' => $sawn, 'quantity' => 72]],
    );

    OutboxEvent::query()->create([
        'aggregate_type' => 'LotTransformation',
        'aggregate_id' => (string) $transformation->id,
        'event_type' => 'lot_transformation.recorded',
        'payload' => [
            'lot_transformation_id' => $transformation->id,
            'processor_company_id' => $processor->id,
            'transformation_type' => 'sawing',
        ],
        'occurred_at' => now(),
        'published_at' => null,
        'attempts' => 0,
        'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription) {
        return $job->subscriptionId === $subscription->id && $job->eventType === 'lot_transformation.recorded';
    });
});
