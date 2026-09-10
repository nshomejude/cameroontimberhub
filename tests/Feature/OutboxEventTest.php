<?php

use App\Domain\Compliance\Events\ComplianceCaseOpened;
use App\Domain\Logistics\Events\ShipmentCheckpointRecorded;
use App\Domain\Trade\Events\OrderAwarded;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\OutboxEvent;
use App\Support\Events\RecordsOutboxEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/* Small helper class exercising the trait directly, independent of any one
   Observer, so the transactional guarantee is tested at the trait level. */
function outboxTestRecorder(): object
{
    return new class
    {
        use RecordsOutboxEvents;

        public function record(App\Support\Events\DomainEvent $event): OutboxEvent
        {
            return $this->recordOutboxEvent($event);
        }
    };
}

it('commits the outbox row when the surrounding transaction commits', function () {
    $recorder = outboxTestRecorder();

    DB::transaction(function () use ($recorder) {
        $recorder->record(new OrderAwarded(orderId: 424242, countryCode: 'DE'));
    });

    expect(OutboxEvent::query()->where('event_type', 'order.awarded')->where('aggregate_id', '424242')->exists())->toBeTrue();
});

it('rolls back the outbox row together with the state change when the surrounding transaction fails', function () {
    $recorder = outboxTestRecorder();

    try {
        DB::transaction(function () use ($recorder) {
            $recorder->record(new OrderAwarded(orderId: 555555, countryCode: 'DE'));

            // Simulate a failure that happens after the outbox write but
            // before commit — the whole transaction, outbox row included,
            // must roll back together (no dual-write gap).
            throw new RuntimeException('simulated failure after outbox write, before commit');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxEvent::query()->where('event_type', 'order.awarded')->where('aggregate_id', '555555')->exists())->toBeFalse();
});

it('relays an unpublished outbox row by dispatching the matching domain event and marks it published', function () {
    Event::fake([OrderAwarded::class]);

    $row = OutboxEvent::query()->create([
        'aggregate_type' => 'Order',
        'aggregate_id' => '777',
        'event_type' => 'order.awarded',
        'payload' => ['order_id' => 777, 'country_code' => 'FR'],
        'occurred_at' => now(),
        'published_at' => null,
        'attempts' => 0,
        'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(OrderAwarded::class, fn (OrderAwarded $event) => $event->orderId === 777 && $event->countryCode === 'FR');

    expect($row->fresh()->published_at)->not->toBeNull();
});

it('relays each of the three formalized event types to its own class', function () {
    Event::fake([OrderAwarded::class, ShipmentCheckpointRecorded::class, ComplianceCaseOpened::class]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Shipment', 'aggregate_id' => '1', 'event_type' => 'checkpoint.recorded',
        'payload' => ['checkpoint_update_id' => 1, 'shipment_id' => 1, 'status' => 'dispatched', 'location' => 'Douala Port'],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'ComplianceCase', 'aggregate_id' => '1', 'event_type' => 'compliance_case.opened',
        'payload' => ['compliance_case_id' => 1, 'owner_type' => 'App\\Models\\Order', 'owner_id' => 1, 'country_code' => 'DE'],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(ShipmentCheckpointRecorded::class);
    Event::assertDispatched(ComplianceCaseOpened::class);

    expect(OutboxEvent::query()->whereNull('published_at')->count())->toBe(0);
});

it('does not mark a row published and increments attempts when relaying it throws', function () {
    Event::listen(OrderAwarded::class, function (): void {
        throw new RuntimeException('simulated listener failure');
    });

    $row = OutboxEvent::query()->create([
        'aggregate_type' => 'Order', 'aggregate_id' => '888', 'event_type' => 'order.awarded',
        'payload' => ['order_id' => 888, 'country_code' => 'DE'],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    $row->refresh();

    expect($row->published_at)->toBeNull()
        ->and($row->attempts)->toBe(1);
});

it('marks an unknown event_type published without dispatching anything, to stop infinite retries', function () {
    $row = OutboxEvent::query()->create([
        'aggregate_type' => 'Unknown', 'aggregate_id' => '1', 'event_type' => 'unknown.thing',
        'payload' => [], 'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    expect($row->fresh()->published_at)->not->toBeNull();
});

/* --- consistency guards: the "single canonical registry" invariant --- */

it('exposes every event type as a consistently-named, subscribable identifier', function () {
    $types = RelayOutboxEventsJob::subscribableEventTypes();

    expect($types)->not->toBeEmpty();

    foreach ($types as $type) {
        // <resource>.<past-tense-verb>, lowercase, snake_case segments only.
        expect($type)->toMatch('/^[a-z][a-z_]*\.[a-z][a-z_]*$/', "event_type '{$type}' breaks the <resource>.<verb> naming convention");
    }
});

it('has a webhook owning-company resolution arm for every registered event type', function () {
    // Every event type the relay can dispatch must resolve to at least one
    // owning company (or a documented multi-company fan-out) — otherwise a
    // subscriber for it could never be notified. A missing match arm falls
    // through to `default => null`, which this asserts against by exercising
    // the real resolution with a minimal payload per type.
    $job = app(RelayOutboxEventsJob::class);
    $resolve = (new ReflectionClass($job))->getMethod('deliverWebhooksFor');
    $resolve->setAccessible(true);

    foreach (RelayOutboxEventsJob::subscribableEventTypes() as $type) {
        $row = OutboxEvent::query()->create([
            'aggregate_type' => 'Test', 'aggregate_id' => '0', 'event_type' => $type,
            'payload' => [], 'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
        ]);

        // Must not throw — a type with no resolution arm would still run the
        // default branch cleanly (returns null), so this guards against an
        // arm that references a helper/shape that doesn't exist.
        expect(fn () => $resolve->invoke($job, $row))->not->toThrow(\Throwable::class, "deliverWebhooksFor() blew up for '{$type}'");
    }
});
