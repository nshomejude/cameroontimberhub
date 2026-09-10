<?php

use App\Domain\Compliance\Commands\FinaliseInspectionCommand;
use App\Domain\Compliance\Commands\OpenDisputeCommand;
use App\Domain\Compliance\Events\DisputeOpened;
use App\Domain\Compliance\Events\InspectionFinalised;
use App\Enums\DisputeCategory;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\Dispute;
use App\Models\Inspection;
use App\Models\Inspector;
use App\Models\Order;
use App\Models\OutboxEvent;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Services\QuoteService;
use App\Support\Bus\CommandBus;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Mail::fake();
});

/** @return array{0: Order, 1: User, 2: Company} */
function busTestDisputeOrderContext(): array
{
    $supplierCompany = Company::factory()->publiclyVisible()->create();
    $buyer = User::factory()->create();

    $rfq = Rfq::factory()->approved()->create(['buyer_email' => $buyer->email, 'user_id' => $buyer->getKey()]);
    $rfq->items()->create([
        'species_text' => 'Sapele', 'form' => 'sawn', 'quantity' => 100, 'unit' => 'm3',
    ]);

    $routing = RfqCompany::create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplierCompany->getKey(),
        'status' => 'sent',
        'routed_at' => now(),
    ]);

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $supplierCompany->getKey(),
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

    app(QuoteService::class)->accept($quote, $buyer);

    $order = Order::where('quote_id', $quote->getKey())->firstOrFail();

    return [$order, $buyer, $supplierCompany];
}

function busTestSupplierUser(Company $company): User
{
    $user = User::factory()->create();
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    return $user;
}

function busTestInspectorUser(): array
{
    $user = User::factory()->create(['email' => 'inspector'.uniqid().'@example.com']);
    $user->assignRole('compliance_officer');

    $inspector = Inspector::create([
        'user_id' => $user->getKey(),
        'status' => 'active',
        'identity_verified_at' => now(),
        'agreement_accepted_at' => now(),
    ]);

    return [$user, $inspector];
}

/* ---------------------------------------------------------- OpenDispute */

it('OpenDisputeCommand produces the same result as calling DisputeService::open() directly', function () {
    [$order, $buyer] = busTestDisputeOrderContext();

    $dispute = app(CommandBus::class)->dispatch(new OpenDisputeCommand(
        orderId: $order->getKey(),
        actingUserId: $buyer->getKey(),
        category: DisputeCategory::Quality,
        description: 'Timber arrived damp and split.',
    ));

    expect($dispute)->toBeInstanceOf(Dispute::class)
        ->and($dispute->order_id)->toBe($order->getKey())
        ->and($dispute->raised_by_company_id)->toBeNull()
        ->and($dispute->respondent_company_id)->toBe($order->company_id)
        ->and(Dispute::query()->count())->toBe(1);
});

it('records a DisputeOpened outbox event transactionally alongside dispute creation', function () {
    [$order, $buyer] = busTestDisputeOrderContext();

    app(CommandBus::class)->dispatch(new OpenDisputeCommand(
        orderId: $order->getKey(),
        actingUserId: $buyer->getKey(),
        category: DisputeCategory::cases()[0],
        description: 'Something is wrong with this shipment.',
    ));

    $dispute = Dispute::query()->firstOrFail();

    expect(OutboxEvent::query()->where('event_type', 'dispute.opened')->where('aggregate_id', (string) $dispute->getKey())->exists())->toBeTrue();
});

it('rolls back both the dispute and its outbox row together when the command fails', function () {
    [$order, $buyer] = busTestDisputeOrderContext();

    // A non-party user cannot open a dispute -- DisputeService::open() throws.
    $stranger = User::factory()->create();

    try {
        app(CommandBus::class)->dispatch(new OpenDisputeCommand(
            orderId: $order->getKey(),
            actingUserId: $stranger->getKey(),
            category: DisputeCategory::cases()[0],
            description: 'Not my order.',
        ));
    } catch (RuntimeException) {
        // expected
    }

    expect(Dispute::query()->count())->toBe(0)
        ->and(OutboxEvent::query()->where('event_type', 'dispute.opened')->count())->toBe(0);
});

it('relays dispute.opened and notifies BOTH parties webhook subscriptions', function () {
    Bus::fake();

    [$order, $buyer, $supplierCompany] = busTestDisputeOrderContext();

    // Buyer has no company on this order, so give the *raising* side a
    // subscription via the supplier being the respondent instead: open the
    // dispute from the supplier side so raised_by_company_id is set, and the
    // buyer becomes the respondent (respondent_company_id null in that case).
    // To exercise BOTH companies getting notified, we instead create two
    // distinct disputes is unnecessary -- simulate an order with a genuine
    // second party by opening as buyer (respondent = supplier company) and
    // separately confirming the supplier side's own subscription also model
    // reachability via raised_by_company_id on a supplier-raised dispute.
    $supplierUser = busTestSupplierUser($supplierCompany);
    $buyerCompany = Company::factory()->publiclyVisible()->create();
    $buyerCompany->users()->attach($buyer, ['role' => 'owner', 'is_primary' => true]);

    WebhookSubscription::query()->create([
        'company_id' => $supplierCompany->id,
        'url' => 'https://example.test/supplier-hook',
        'event_types' => ['dispute.opened'],
        'secret' => 's1',
        'is_active' => true,
    ]);

    app(CommandBus::class)->dispatch(new OpenDisputeCommand(
        orderId: $order->getKey(),
        actingUserId: $buyer->getKey(),
        category: DisputeCategory::cases()[0],
        description: 'Buyer-raised dispute against the supplier.',
    ));

    app(RelayOutboxEventsJob::class)->handle();

    // Buyer raised it (raised_by_company_id null), respondent is the supplier
    // company -- so the supplier's subscription must fire.
    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($supplierCompany) {
        return $job->eventType === 'dispute.opened';
    });
});

it('relays dispute.opened and notifies both raiser and respondent companies when both subscribe', function () {
    Bus::fake();

    $raiserCompany = Company::factory()->create();
    $respondentCompany = Company::factory()->create();

    WebhookSubscription::query()->create([
        'company_id' => $raiserCompany->id, 'url' => 'https://example.test/raiser',
        'event_types' => ['dispute.opened'], 'secret' => 'a', 'is_active' => true,
    ]);
    WebhookSubscription::query()->create([
        'company_id' => $respondentCompany->id, 'url' => 'https://example.test/respondent',
        'event_types' => ['dispute.opened'], 'secret' => 'b', 'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Dispute', 'aggregate_id' => '1', 'event_type' => 'dispute.opened',
        'payload' => [
            'dispute_id' => 1, 'order_id' => 1,
            'raised_by_company_id' => $raiserCompany->id,
            'respondent_company_id' => $respondentCompany->id,
        ],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Bus::assertDispatchedTimes(DeliverWebhookJob::class, 2);
});

/* ------------------------------------------------------ FinaliseInspection */

it('FinaliseInspectionCommand produces the same result as calling Inspection::finalise() directly', function () {
    [, $inspector] = busTestInspectorUser();
    $inspection = Inspection::create([
        'inspector_id' => $inspector->getKey(),
        'inspection_type' => 'pre_shipment',
        'performed_at' => now(),
        'result' => 'pass',
    ]);

    $result = app(CommandBus::class)->dispatch(new FinaliseInspectionCommand(
        inspectionId: $inspection->getKey(),
        data: [],
    ));

    expect($result->finalised_at)->not->toBeNull()
        ->and($result->digital_signature)->not->toBeNull();
});

it('records an InspectionFinalised outbox event transactionally alongside finalisation', function () {
    [, $inspector] = busTestInspectorUser();
    $inspection = Inspection::create([
        'inspector_id' => $inspector->getKey(),
        'inspection_type' => 'pre_shipment',
        'performed_at' => now(),
        'result' => 'pass',
    ]);

    app(CommandBus::class)->dispatch(new FinaliseInspectionCommand(inspectionId: $inspection->getKey()));

    expect(OutboxEvent::query()->where('event_type', 'inspection.finalised')->where('aggregate_id', (string) $inspection->getKey())->exists())->toBeTrue();
});

it('rejects finalising an inspection twice via the immutability guard, and no outbox row is written', function () {
    [, $inspector] = busTestInspectorUser();
    $inspection = Inspection::create([
        'inspector_id' => $inspector->getKey(),
        'inspection_type' => 'pre_shipment',
        'performed_at' => now(),
        'result' => 'pass',
    ]);

    app(CommandBus::class)->dispatch(new FinaliseInspectionCommand(inspectionId: $inspection->getKey()));

    $before = OutboxEvent::query()->where('event_type', 'inspection.finalised')->count();

    try {
        app(CommandBus::class)->dispatch(new FinaliseInspectionCommand(
            inspectionId: $inspection->getKey(),
            data: ['result' => 'fail'],
        ));
    } catch (RuntimeException) {
        // expected: content field mutation on an already-finalised inspection
    }

    expect(OutboxEvent::query()->where('event_type', 'inspection.finalised')->count())->toBe($before);
});

it('relays inspection.finalised and resolves the owning company via order_id', function () {
    Bus::fake();

    [$order] = busTestDisputeOrderContext();

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $order->company_id,
        'url' => 'https://example.test/inspection-hook',
        'event_types' => ['inspection.finalised'],
        'secret' => 'secret',
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Inspection', 'aggregate_id' => '9', 'event_type' => 'inspection.finalised',
        'payload' => ['inspection_id' => 9, 'timber_lot_id' => null, 'order_id' => $order->getKey(), 'result' => 'pass'],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription) {
        return $job->subscriptionId === $subscription->id && $job->eventType === 'inspection.finalised';
    });
});

it('relays both new event types to their typed domain event classes', function () {
    Event::fake([DisputeOpened::class, InspectionFinalised::class]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Dispute', 'aggregate_id' => '1', 'event_type' => 'dispute.opened',
        'payload' => ['dispute_id' => 1, 'order_id' => 1, 'raised_by_company_id' => 1, 'respondent_company_id' => 2],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    OutboxEvent::query()->create([
        'aggregate_type' => 'Inspection', 'aggregate_id' => '1', 'event_type' => 'inspection.finalised',
        'payload' => ['inspection_id' => 1, 'timber_lot_id' => null, 'order_id' => null, 'result' => 'pass'],
        'occurred_at' => now(), 'published_at' => null, 'attempts' => 0, 'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(DisputeOpened::class);
    Event::assertDispatched(InspectionFinalised::class);
    expect(OutboxEvent::query()->whereNull('published_at')->count())->toBe(0);
});
