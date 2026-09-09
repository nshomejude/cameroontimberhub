<?php

use App\Domain\Trade\Commands\RecordOrderDeliveryCommand;
use App\Domain\Trade\Commands\RecordOrderShipmentCommand;
use App\Domain\Trade\Events\OrderDelivered;
use App\Domain\Trade\Events\OrderShipped;
use App\Enums\CompanyUserRole;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\OrderService;
use App\Support\Bus\CommandBus;
use App\Support\Events\RecordsOutboxEvents;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
 * Regression/parity + outbox + relay + webhook coverage for the Trade
 * lifecycle's shipped/delivered Commands (architecture plan, Phase 1
 * extension of Task 0.1/0.2/0.5).
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** A confirmed order, ready to be shipped, belonging to a real company. */
function shipmentCommandOrder(): Order
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
    $quote->update(['status' => QuoteStatus::Accepted]);

    $order = app(OrderService::class)->createFromQuote($quote);

    return app(OrderService::class)->confirm($order);
}

/* -------------------------------------------------------- regression parity */

it('RecordOrderShipmentCommand produces the same order state as calling OrderService::ship() directly', function () {
    $order = shipmentCommandOrder();

    $result = app(CommandBus::class)->dispatch(new RecordOrderShipmentCommand(orderId: $order->getKey()));

    expect($result)->toBeInstanceOf(Order::class);
    expect($result->status)->toBe(OrderStatus::Shipped);
    expect($result->shipped_at)->not->toBeNull();
});

it('RecordOrderDeliveryCommand produces the same order state as calling OrderService::deliver() directly', function () {
    $order = shipmentCommandOrder();
    $order = app(OrderService::class)->ship($order);

    $result = app(CommandBus::class)->dispatch(new RecordOrderDeliveryCommand(orderId: $order->getKey()));

    expect($result)->toBeInstanceOf(Order::class);
    expect($result->status)->toBe(OrderStatus::Delivered);
    expect($result->delivered_at)->not->toBeNull();
});

it('rolls back the shipment command when the underlying transition is illegal', function () {
    $order = shipmentCommandOrder();
    // Awarded/confirmed order shipped once already -> illegal to ship again.
    app(OrderService::class)->ship($order);

    expect(fn () => app(CommandBus::class)->dispatch(new RecordOrderShipmentCommand(orderId: $order->getKey())))
        ->toThrow(RuntimeException::class);

    expect(OutboxEvent::query()->where('event_type', 'order.shipped')->where('aggregate_id', (string) $order->getKey())->count())
        ->toBe(0);
});

/* --------------------------------------------------------------- outbox tx */

it('records the OrderShipped outbox row transactionally with the state change', function () {
    $order = shipmentCommandOrder();

    app(CommandBus::class)->dispatch(new RecordOrderShipmentCommand(orderId: $order->getKey()));

    expect(OutboxEvent::query()->where('event_type', 'order.shipped')->where('aggregate_id', (string) $order->getKey())->exists())
        ->toBeTrue();
});

it('rolls back the OrderShipped outbox row together with a forced failure inside the same transaction', function () {
    $order = shipmentCommandOrder();

    $recorder = new class
    {
        use RecordsOutboxEvents;

        public function record(App\Support\Events\DomainEvent $event): OutboxEvent
        {
            return $this->recordOutboxEvent($event);
        }
    };

    try {
        DB::transaction(function () use ($recorder, $order) {
            $recorder->record(new OrderShipped(orderId: $order->getKey()));

            throw new RuntimeException('simulated failure after outbox write, before commit');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxEvent::query()->where('event_type', 'order.shipped')->where('aggregate_id', (string) $order->getKey())->exists())
        ->toBeFalse();
});

it('records the OrderDelivered outbox row transactionally with the state change', function () {
    $order = shipmentCommandOrder();
    $order = app(OrderService::class)->ship($order);

    app(CommandBus::class)->dispatch(new RecordOrderDeliveryCommand(orderId: $order->getKey()));

    expect(OutboxEvent::query()->where('event_type', 'order.delivered')->where('aggregate_id', (string) $order->getKey())->exists())
        ->toBeTrue();
});

it('rolls back the OrderDelivered outbox row together with a forced failure inside the same transaction', function () {
    $order = shipmentCommandOrder();
    $order = app(OrderService::class)->ship($order);

    $recorder = new class
    {
        use RecordsOutboxEvents;

        public function record(App\Support\Events\DomainEvent $event): OutboxEvent
        {
            return $this->recordOutboxEvent($event);
        }
    };

    try {
        DB::transaction(function () use ($recorder, $order) {
            $recorder->record(new OrderDelivered(orderId: $order->getKey()));

            throw new RuntimeException('simulated failure after outbox write, before commit');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(OutboxEvent::query()->where('event_type', 'order.delivered')->where('aggregate_id', (string) $order->getKey())->exists())
        ->toBeFalse();
});

/* ------------------------------------------------------------------- relay */

it('relays order.shipped and order.delivered outbox rows to their domain events', function () {
    Event::fake([OrderShipped::class, OrderDelivered::class]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Order', 'aggregate_id' => '901', 'event_type' => 'order.shipped',
        'payload' => ['order_id' => 901], 'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Order', 'aggregate_id' => '902', 'event_type' => 'order.delivered',
        'payload' => ['order_id' => 902], 'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(OrderShipped::class, fn (OrderShipped $e) => $e->orderId === 901);
    Event::assertDispatched(OrderDelivered::class, fn (OrderDelivered $e) => $e->orderId === 902);

    expect(OutboxEvent::query()->whereNull('published_at')->count())->toBe(0);
});

/* ---------------------------------------------------------------- webhooks */

function shipmentWebhookCompany(): Company
{
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    return $company;
}

it('dispatches a DeliverWebhookJob for an order.shipped outbox event matching an active subscription', function () {
    Bus::fake();

    $company = shipmentWebhookCompany();
    $order = shipmentCommandOrder();
    $order->forceFill(['company_id' => $company->getKey()])->save();

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['order.shipped'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'order.shipped',
        'aggregate_type' => 'Order',
        'aggregate_id' => (string) $order->id,
        'payload' => ['order_id' => $order->id],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription, $order) {
        return $job->subscriptionId === $subscription->id
            && $job->eventType === 'order.shipped'
            && (int) $job->payload['order_id'] === $order->id;
    });
});

it('dispatches a DeliverWebhookJob for an order.delivered outbox event matching an active subscription', function () {
    Bus::fake();

    $company = shipmentWebhookCompany();
    $order = shipmentCommandOrder();
    $order->forceFill(['company_id' => $company->getKey()])->save();

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['order.delivered'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'order.delivered',
        'aggregate_type' => 'Order',
        'aggregate_id' => (string) $order->id,
        'payload' => ['order_id' => $order->id],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription, $order) {
        return $job->subscriptionId === $subscription->id
            && $job->eventType === 'order.delivered'
            && (int) $job->payload['order_id'] === $order->id;
    });
});
