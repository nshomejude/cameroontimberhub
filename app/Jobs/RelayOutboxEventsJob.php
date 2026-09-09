<?php

namespace App\Jobs;

use App\Domain\Compliance\Events\ComplianceCaseOpened;
use App\Domain\Logistics\Events\ShipmentCheckpointRecorded;
use App\Domain\Trade\Events\OrderAwarded;
use App\Models\OutboxEvent;
use App\Support\Events\DomainEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The outbox relay (architecture plan §"Event-Driven Backbone", Task 0.2):
 * reads unpublished `outbox_events` rows and dispatches the matching real
 * Laravel/domain event so its queued listeners run, then marks the row
 * published. Scheduled every 10 seconds in routes/console.php.
 *
 * `event_type` string -> event class mapping is a plain match/array (below,
 * EVENT_MAP) — no config file, since these three events are the entire
 * mapping today and it is trivial to extend when more are formalized.
 *
 * Never throws uncaught: a per-row failure increments that row's `attempts`
 * and leaves it unpublished for the next run to retry, up to MAX_ATTEMPTS,
 * after which it is logged and left alone (not deleted) rather than retried
 * forever.
 */
class RelayOutboxEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** event_type string -> event class implementing DomainEvent::fromPayload(). */
    private const EVENT_MAP = [
        'order.awarded' => OrderAwarded::class,
        'shipment.checkpoint_recorded' => ShipmentCheckpointRecorded::class,
        'compliance.case_opened' => ComplianceCaseOpened::class,
    ];

    private const MAX_ATTEMPTS = 5;

    private const BATCH_SIZE = 200;

    public function handle(): void
    {
        OutboxEvent::query()
            ->whereNull('published_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get()
            ->each(function (OutboxEvent $row): void {
                $this->relay($row);
            });
    }

    private function relay(OutboxEvent $row): void
    {
        $eventClass = self::EVENT_MAP[$row->event_type] ?? null;

        if ($eventClass === null) {
            // Unknown/retired event_type: nothing will ever be able to
            // dispatch it, so mark it published rather than retrying forever.
            Log::error('RelayOutboxEventsJob: unknown event_type, marking published to stop retrying.', [
                'outbox_event_id' => $row->id,
                'event_type' => $row->event_type,
            ]);

            $row->update(['published_at' => now()]);

            return;
        }

        try {
            /** @var DomainEvent $domainEvent */
            $domainEvent = $eventClass::fromPayload($row->payload ?? []);

            event($domainEvent);

            // Task 0.5 (webhooks) integration point: once webhook_subscriptions
            // exist, this is where a matching active subscription should get a
            // DeliverWebhookJob dispatched for this row. Deliberately isolated
            // to its own method — add the call inside deliverWebhooksFor()
            // below, do not restructure relay()/handle().
            $this->deliverWebhooksFor($row);

            $row->update(['published_at' => now()]);
        } catch (Throwable $e) {
            $row->increment('attempts');

            if ($row->attempts >= self::MAX_ATTEMPTS) {
                Log::error('RelayOutboxEventsJob: giving up on outbox row after max attempts.', [
                    'outbox_event_id' => $row->id,
                    'event_type' => $row->event_type,
                    'attempts' => $row->attempts,
                    'exception' => $e->getMessage(),
                ]);
            } else {
                Log::warning('RelayOutboxEventsJob: failed to relay outbox row, will retry.', [
                    'outbox_event_id' => $row->id,
                    'event_type' => $row->event_type,
                    'attempts' => $row->attempts,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Integration point for Task 0.5 (webhook delivery) — see the doc
     * comment in relay() above. Currently a no-op: webhook_subscriptions /
     * webhook_deliveries / DeliverWebhookJob do not exist yet.
     */
    private function deliverWebhooksFor(OutboxEvent $row): void
    {
        // no-op — Task 0.5 adds its dispatch call here.
    }
}
