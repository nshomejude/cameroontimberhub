<?php

namespace App\Jobs;

use App\Domain\Catalog\Events\ProductPublished;
use App\Domain\Commerce\Events\PaymentCompleted;
use App\Domain\Commerce\Events\SubscriptionActivated;
use App\Domain\Compliance\Events\ComplianceCaseOpened;
use App\Domain\Compliance\Events\DisputeOpened;
use App\Domain\Compliance\Events\InspectionFinalised;
use App\Domain\Identity\Events\CompanyVerified;
use App\Domain\Logistics\Events\LotTransformationRecorded;
use App\Domain\Logistics\Events\ShipmentCheckpointRecorded;
use App\Domain\Trade\Events\OrderAwarded;
use App\Domain\Trade\Events\OrderDelivered;
use App\Domain\Trade\Events\OrderShipped;
use App\Domain\Trade\Events\QuoteDeclined;
use App\Domain\Trade\Events\QuoteWithdrawn;
use App\Jobs\DeliverWebhookJob;
use App\Models\Company;
use App\Models\Inspection;
use App\Models\LotTransformation;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Shipment;
use App\Models\TimberLot;
use App\Models\WebhookSubscription;
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
 * `event_type` string -> event class mapping is a plain array (below,
 * EVENT_MAP) — no config file. It is the single canonical registry of every
 * event type the platform emits; anything that needs the list of valid
 * event types (e.g. the webhook-subscription form) derives it from
 * subscribableEventTypes() rather than keeping a second hand-maintained
 * copy. To add an event: add one EVENT_MAP entry, one owning-company arm in
 * deliverWebhooksFor(), and one row in docs/api/WEBHOOKS.md's catalog.
 *
 * Never throws uncaught: a per-row failure increments that row's `attempts`
 * and leaves it unpublished for the next run to retry, up to MAX_ATTEMPTS,
 * after which it is logged and left alone (not deleted) rather than retried
 * forever.
 */
class RelayOutboxEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Per-row failures are absorbed inside relay() (attempts counter on the
     * outbox row itself). $tries/backoff/failed() here cover a hard failure of
     * the job as a whole — the initial query throwing, the DB being down — so
     * a dead outbox relay is visible rather than silently stalling every
     * queued listener on the platform.
     */
    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /** event_type string -> event class implementing DomainEvent::fromPayload(). */
    private const EVENT_MAP = [
        'order.awarded' => OrderAwarded::class,
        'checkpoint.recorded' => ShipmentCheckpointRecorded::class,
        'compliance_case.opened' => ComplianceCaseOpened::class,
        // Trade lifecycle Phase 1 additions (order.shipped / order.delivered):
        'order.shipped' => OrderShipped::class,
        'order.delivered' => OrderDelivered::class,
        // Quote lifecycle additions (quote.declined / quote.withdrawn) — see
        // App\Domain\Trade\Commands\{Decline,Withdraw}QuoteHandler:
        'quote.declined' => QuoteDeclined::class,
        'quote.withdrawn' => QuoteWithdrawn::class,
        // Compliance & Trust additions (dispute lifecycle / inspection
        // finalisation) — see App\Domain\Compliance\Commands\{Open
        // Dispute,FinaliseInspection}Handler:
        'dispute.opened' => DisputeOpened::class,
        'inspection.finalised' => InspectionFinalised::class,
        // Logistics & Traceability addition (mass-balance transformations) —
        // see App\Domain\Logistics\Commands\RecordLotTransformationHandler:
        'lot_transformation.recorded' => LotTransformationRecorded::class,
        // Identity & Access addition (Phase 4) — see
        // App\Domain\Identity\Commands\ApproveVerificationHandler:
        'company.verified' => CompanyVerified::class,
        // Catalog addition (Phase 4) — see App\Observers\ProductObserver /
        // App\Domain\Catalog\Commands\PublishProductHandler:
        'product.published' => ProductPublished::class,
        // --- Commerce & Billing addition (Phase 4) — see App\Domain\Commerce\
        // Commands\{AssignSubscription,RecordPaymentCompletion}Handler. Small,
        // separate, additive block; does not touch any other entry above. ---
        'subscription.activated' => SubscriptionActivated::class,
        'payment.completed' => PaymentCompleted::class,
    ];

    private const MAX_ATTEMPTS = 5;

    /**
     * The canonical list of every event type the platform emits — the keys
     * of EVENT_MAP. Anything that needs "the valid event types" (the webhook
     * subscription form, docs tooling, tests) reads this instead of keeping
     * its own copy that could drift.
     *
     * @return list<string>
     */
    public static function subscribableEventTypes(): array
    {
        return array_keys(self::EVENT_MAP);
    }

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
     * Task 0.5 (webhook delivery) integration point. Resolves the company
     * that "owns" this outbox row's event, then dispatches a
     * DeliverWebhookJob for every active WebhookSubscription of that
     * company whose event_types includes this row's event_type.
     *
     * None of the event payloads carry company_id directly, so each needs
     * its own lookup:
     *  - order.awarded / order.shipped / order.delivered: Order::company_id
     *    (direct column on the order).
     *  - checkpoint.recorded: Shipment::order->company_id (a
     *    Shipment belongs to an Order, which carries company_id).
     *  - compliance_case.opened: the case's polymorphic owner
     *    (owner_type/owner_id) — today only Company, but resolved
     *    defensively for Order/Shipment/TimberLot too per ComplianceCase's
     *    doc block ("Company today; TimberLot, Shipment as those need
     *    assessment").
     */
    private function deliverWebhooksFor(OutboxEvent $row): void
    {
        $companyId = match ($row->event_type) {
            // order.shipped / order.delivered resolve identically to
            // order.awarded — Order::company_id is a direct column.
            'order.awarded', 'order.shipped', 'order.delivered' => Order::query()->find($row->payload['order_id'] ?? null)?->company_id,
            'checkpoint.recorded' => Shipment::query()
                ->with('order:id,company_id')
                ->find($row->payload['shipment_id'] ?? null)
                ?->order?->company_id,
            'compliance_case.opened' => $this->resolveComplianceCaseCompanyId($row->payload ?? []),
            // quote.declined / quote.withdrawn: a Quote's owning company is
            // the SUPPLIER who submitted it (Quote::company_id), carried
            // directly on the event payload by {Decline,Withdraw}QuoteHandler.
            'quote.declined', 'quote.withdrawn' => isset($row->payload['company_id']) ? (int) $row->payload['company_id'] : null,
            // inspection.finalised: resolved via whichever of
            // timber_lot_id/order_id is set on the payload -> owning
            // company, mirroring compliance_case.opened's owner resolution.
            // See resolveInspectionCompanyId() below. (Compliance & Trust
            // additions — see App\Domain\Compliance\Commands\
            // FinaliseInspectionHandler.)
            'inspection.finalised' => $this->resolveInspectionCompanyId($row->payload ?? []),
            // lot_transformation.recorded: a transformation's owning company
            // is the company that owns the INPUT TimberLot(s) — not
            // necessarily the processor who performed it (a processor can
            // work on lots it does not own). See resolveLotTransformationCompanyId()
            // below. (Logistics & Traceability addition — see
            // App\Domain\Logistics\Commands\RecordLotTransformationHandler.)
            'lot_transformation.recorded' => $this->resolveLotTransformationCompanyId($row->payload ?? []),
            // Identity & Access addition (Phase 4): company.verified carries
            // the verified company's id directly on the payload (a direct
            // column on VerificationRequest, resolved by
            // App\Domain\Identity\Commands\ApproveVerificationHandler) — no
            // extra lookup needed.
            'company.verified' => isset($row->payload['company_id']) ? (int) $row->payload['company_id'] : null,
            // Catalog addition (Phase 4): product.published carries the
            // owning company id directly on the payload (Product::company_id
            // is a direct column) — no extra lookup needed.
            'product.published' => isset($row->payload['company_id']) ? (int) $row->payload['company_id'] : null,
            // --- Commerce & Billing addition (Phase 4): both new events
            // carry the owning company id directly on the payload —
            // Subscription::company_id and Payment::company_id are both
            // direct columns (see App\Domain\Commerce\Events\
            // {SubscriptionActivated,PaymentCompleted}) — no extra lookup
            // needed. Small, separate, additive block. ---
            'subscription.activated', 'payment.completed' => isset($row->payload['company_id']) ? (int) $row->payload['company_id'] : null,
            default => null,
        };

        // dispute.opened is the one event with (up to) TWO owning companies
        // — both raised_by_company_id and respondent_company_id, carried
        // directly on the payload by OpenDisputeHandler — so it is resolved
        // to a list rather than the single $companyId above. (Compliance &
        // Trust addition — see App\Domain\Compliance\Commands\OpenDisputeHandler.)
        $companyIds = $row->event_type === 'dispute.opened'
            ? array_values(array_unique(array_filter([
                isset($row->payload['raised_by_company_id']) ? (int) $row->payload['raised_by_company_id'] : null,
                isset($row->payload['respondent_company_id']) ? (int) $row->payload['respondent_company_id'] : null,
            ])))
            : ($companyId === null ? [] : [$companyId]);

        foreach ($companyIds as $id) {
            WebhookSubscription::query()
                ->where('company_id', $id)
                ->where('is_active', true)
                ->get()
                ->each(function (WebhookSubscription $subscription) use ($row): void {
                    if ($subscription->subscribesTo($row->event_type)) {
                        DeliverWebhookJob::dispatch($subscription->id, $row->event_type, $row->payload ?? []);
                    }
                });
        }
    }

    /**
     * Compliance & Trust addition: resolve an Inspection's owning company
     * via whichever of timber_lot_id/order_id is set (an Inspection carries
     * both nullable FKs — see App\Models\Inspection), mirroring
     * resolveComplianceCaseCompanyId()'s pattern below.
     */
    private function resolveInspectionCompanyId(array $payload): ?int
    {
        if (! empty($payload['timber_lot_id'])) {
            return TimberLot::query()->find($payload['timber_lot_id'])?->company_id;
        }

        if (! empty($payload['order_id'])) {
            return Order::query()->find($payload['order_id'])?->company_id;
        }

        return null;
    }

    /**
     * Logistics & Traceability addition: resolve a LotTransformation's
     * owning company as the company that owns its FIRST input TimberLot
     * (TimberLot::company_id — a direct column, see app/Models/TimberLot.php).
     * A transformation's `processor_company_id` is deliberately NOT used
     * here — a processor can work on lots it does not own, and the webhook
     * subscriber that should be notified is the lot's owner, not whoever
     * physically performed the transformation.
     */
    private function resolveLotTransformationCompanyId(array $payload): ?int
    {
        $lotTransformationId = $payload['lot_transformation_id'] ?? null;

        if ($lotTransformationId === null) {
            return null;
        }

        return LotTransformation::query()
            ->find($lotTransformationId)
            ?->inputLots()
            ->value('company_id');
    }

    /**
     * The relay is dispatched by the scheduler every 10 seconds; if the job
     * itself dies (not a single row — the whole run), that must not vanish
     * into the queue's failed_jobs table unremarked.
     */
    public function failed(?Throwable $e): void
    {
        Log::channel('errors')->error('RelayOutboxEventsJob failed permanently — the transactional outbox relay is not running.', [
            'job' => self::class,
            'unpublished_outbox_rows' => OutboxEvent::query()->whereNull('published_at')->count(),
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }

    private function resolveComplianceCaseCompanyId(array $payload): ?int
    {
        $ownerType = $payload['owner_type'] ?? null;
        $ownerId = $payload['owner_id'] ?? null;

        if ($ownerType === null || $ownerId === null) {
            return null;
        }

        return match ($ownerType) {
            Company::class => (int) $ownerId,
            Order::class => Order::query()->find($ownerId)?->company_id,
            Shipment::class => Shipment::query()->with('order:id,company_id')->find($ownerId)?->order?->company_id,
            TimberLot::class => TimberLot::query()->find($ownerId)?->company_id,
            default => null,
        };
    }
}
