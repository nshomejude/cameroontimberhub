<?php

namespace App\Support\Events;

/**
 * A typed domain event, formalizing what used to be inline Observer side
 * effects (architecture plan, "Event-Driven Backbone").
 *
 * Design choice (plan Task 0.2 step 7): implementations of this interface
 * ARE the real Laravel event classes listeners subscribe to via
 * `EventServiceProvider::$listen` — there is no separate adapter/wrapper
 * event. This is simplest: one class per event, dispatched directly via
 * `event(new SomeDomainEvent(...))`, and reconstructable from a stored
 * outbox payload via a `fromPayload(array $payload): static` factory
 * (see App\Jobs\RelayOutboxEventsJob, which needs that to rebuild the event
 * from the JSON payload it read back off the `outbox_events` row).
 */
interface DomainEvent
{
    /** The aggregate's type name, e.g. "Order", "Shipment". */
    public function aggregateType(): string;

    /** The aggregate's primary key. */
    public function aggregateId(): int|string;

    /** A stable, dot-namespaced event type string, e.g. "order.awarded". */
    public function eventType(): string;

    /** The JSON-serializable payload stored on the outbox row. */
    public function payload(): array;
}
