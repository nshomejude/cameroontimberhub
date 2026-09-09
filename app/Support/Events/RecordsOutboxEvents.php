<?php

namespace App\Support\Events;

use App\Models\OutboxEvent;

/**
 * Give any class (typically an Observer or a queued Listener) the ability
 * to record a domain event to the transactional outbox.
 *
 * REQUIREMENT: `recordOutboxEvent()` MUST be called from INSIDE the same DB
 * transaction as the state change that triggered the event. This trait does
 * NOT open its own transaction — it relies entirely on the caller already
 * being inside one (e.g. an Eloquent `created` Observer callback fires
 * while the model's own `DB::transaction()` in the owning Service is still
 * open, so a plain insert here naturally joins that transaction). This is
 * what makes the outbox pattern solve the dual-write problem: if the
 * surrounding transaction rolls back, this row rolls back with it, so the
 * state change and the fact that anyone was told about it can never
 * diverge. Calling this OUTSIDE an open transaction (e.g. after the state
 * change has already committed) reintroduces the dual-write gap this
 * pattern exists to close.
 */
trait RecordsOutboxEvents
{
    protected function recordOutboxEvent(DomainEvent $event): OutboxEvent
    {
        return OutboxEvent::query()->create([
            'aggregate_type' => $event->aggregateType(),
            'aggregate_id' => (string) $event->aggregateId(),
            'event_type' => $event->eventType(),
            'payload' => $event->payload(),
            'occurred_at' => now(),
            'published_at' => null,
            'attempts' => 0,
            'created_at' => now(),
        ]);
    }
}
