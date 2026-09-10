<?php

use App\Enums\CompanyUserRole;
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
use App\Models\WebhookDelivery;
use App\Models\WebhookSubscription;
use App\Services\Webhooks\WebhookDeliveryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function webhookCompanyWithOwner(): Company
{
    $company = Company::factory()->create();
    $owner = User::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    return $company;
}

/** Creates a minimal real Order belonging to the given company, satisfying orders' required FKs. */
function webhookOrderForCompany(Company $company): Order
{
    $buyer = User::factory()->create();
    $rfq = Rfq::factory()->approved()->create(['user_id' => $buyer->getKey(), 'buyer_email' => $buyer->email]);

    $routing = RfqCompany::firstOrCreate(
        ['rfq_id' => $rfq->getKey(), 'company_id' => $company->getKey()],
        ['status' => 'sent', 'routed_at' => now()],
    );

    $quote = Quote::factory()->submitted()->create([
        'rfq_id' => $rfq->getKey(),
        'company_id' => $company->getKey(),
        'rfq_company_id' => $routing->getKey(),
        'status' => QuoteStatus::Accepted,
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

it('signs a payload with HMAC-SHA256 verifiable against a known secret', function () {
    $company = Company::factory()->create();
    $plainTextSecret = 'my-known-secret';

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['order.awarded'],
        'secret_hash' => WebhookSubscription::hashSecret($plainTextSecret),
        'is_active' => true,
    ]);

    $payload = ['order_id' => 1, 'country_code' => 'DE'];

    $service = app(WebhookDeliveryService::class);
    $signature = $service->sign($payload, $subscription);

    // The receiver independently derives the same signing key by hashing
    // their own copy of the plaintext secret (see WebhookDeliveryService's
    // doc block), then computes the same HMAC over the same JSON payload.
    $expectedKey = hash('sha256', $plainTextSecret);
    $expected = hash_hmac('sha256', json_encode($payload), $expectedKey);

    expect($signature)->toBe($expected);
});

it('follows the fixed 3-attempt retry/backoff schedule and marks a delivery dead after the final failure', function () {
    Bus::fake();

    $company = Company::factory()->create();
    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['order.awarded'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    Http::fake(['example.test/*' => Http::response('fail', 500)]);

    // Attempt 1: immediate failure -> should schedule attempt 2 with a ~1 minute delay.
    (new DeliverWebhookJob($subscription->id, 'order.awarded', ['order_id' => 1], attempt: 1))
        ->handle(app(WebhookDeliveryService::class));

    $delivery = WebhookDelivery::query()->first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->isDelivered())->toBeFalse()
        ->and($delivery->isFailedPermanently())->toBeFalse();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($delivery) {
        return $job->attempt === 2
            && $job->deliveryId === $delivery->id
            && $job->delay !== null
            && now()->diffInSeconds($job->delay, false) >= 55
            && now()->diffInSeconds($job->delay, false) <= 65;
    });

    // Attempt 2: failure -> should schedule attempt 3 with a ~10 minute delay.
    (new DeliverWebhookJob($subscription->id, 'order.awarded', ['order_id' => 1], attempt: 2, deliveryId: $delivery->id))
        ->handle(app(WebhookDeliveryService::class));

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($delivery) {
        return $job->attempt === 3
            && $job->deliveryId === $delivery->id
            && $job->delay !== null
            && now()->diffInSeconds($job->delay, false) >= 595
            && now()->diffInSeconds($job->delay, false) <= 605;
    });

    // Attempt 3: failure -> marked dead, no further retry dispatched.
    (new DeliverWebhookJob($subscription->id, 'order.awarded', ['order_id' => 1], attempt: 3, deliveryId: $delivery->id))
        ->handle(app(WebhookDeliveryService::class));

    $delivery->refresh();
    expect($delivery->attempt)->toBe(3)
        ->and($delivery->isFailedPermanently())->toBeTrue();

    Bus::assertDispatched(DeliverWebhookJob::class, 2); // attempt 2 + attempt 3 dispatches only, no 4th
});

it('marks a delivery as delivered on a 2xx response and does not retry', function () {
    Bus::fake();
    Http::fake(['example.test/*' => Http::response('ok', 200)]);

    $company = Company::factory()->create();
    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['order.awarded'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    (new DeliverWebhookJob($subscription->id, 'order.awarded', ['order_id' => 1], attempt: 1))
        ->handle(app(WebhookDeliveryService::class));

    $delivery = WebhookDelivery::query()->first();
    expect($delivery->isDelivered())->toBeTrue()
        ->and($delivery->response_code)->toBe(200);

    Bus::assertNotDispatched(DeliverWebhookJob::class);
});

it('dispatches a DeliverWebhookJob when an outbox event matches an active subscription event_types', function () {
    Bus::fake();

    $company = webhookCompanyWithOwner();
    $order = webhookOrderForCompany($company);

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['order.awarded'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'order.awarded',
        'aggregate_type' => 'Order',
        'aggregate_id' => (string) $order->id,
        'payload' => ['order_id' => $order->id, 'country_code' => 'DE'],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription, $order) {
        return $job->subscriptionId === $subscription->id
            && $job->eventType === 'order.awarded'
            && (int) $job->payload['order_id'] === $order->id;
    });
});

it('does not dispatch any delivery job when no subscription matches the outbox event type', function () {
    Bus::fake();

    $company = webhookCompanyWithOwner();
    $order = webhookOrderForCompany($company);

    // Subscription exists but for a different event type.
    WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['checkpoint.recorded'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);

    OutboxEvent::query()->create([
        'event_type' => 'order.awarded',
        'aggregate_type' => 'Order',
        'aggregate_id' => (string) $order->id,
        'payload' => ['order_id' => $order->id, 'country_code' => 'DE'],
        'attempts' => 0,
        'occurred_at' => now(),
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertNotDispatched(DeliverWebhookJob::class);
});

it('admin webhook delivery log is reachable only with api-keys.manage', function () {
    (new RolesAndPermissionsSeeder())->run();

    $company = webhookCompanyWithOwner();
    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['order.awarded'],
        'secret_hash' => WebhookSubscription::hashSecret('secret'),
        'is_active' => true,
    ]);
    WebhookDelivery::query()->create([
        'subscription_id' => $subscription->id,
        'event_type' => 'order.awarded',
        'payload' => ['order_id' => 1],
        'attempt' => 1,
    ]);

    $noPermUser = User::factory()->create();
    $this->actingAs($noPermUser);
    $this->get('/admin/webhook-deliveries')->assertForbidden();

    $adminUser = User::factory()->create();
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
    $this->get('/admin/webhook-deliveries')->assertOk();
});

it('scopes the exporter self-service webhook resource to the signed-in company only', function () {
    $companyA = webhookCompanyWithOwner();
    $companyB = webhookCompanyWithOwner();

    $subscriptionA = WebhookSubscription::query()->create([
        'company_id' => $companyA->id,
        'url' => 'https://example.test/a',
        'event_types' => ['order.awarded'],
        'secret_hash' => WebhookSubscription::hashSecret('secret-a'),
        'is_active' => true,
    ]);

    $subscriptionB = WebhookSubscription::query()->create([
        'company_id' => $companyB->id,
        'url' => 'https://example.test/b',
        'event_types' => ['order.awarded'],
        'secret_hash' => WebhookSubscription::hashSecret('secret-b'),
        'is_active' => true,
    ]);

    $userA = $companyA->users()->wherePivot('role', CompanyUserRole::Owner)->first();

    $this->actingAs($userA);

    $this->get('/dashboard/webhook-subscriptions')->assertOk();

    $query = App\Filament\Exporter\Resources\WebhookSubscriptions\WebhookSubscriptionResource::getEloquentQuery();
    expect($query->pluck('id')->all())->toBe([$subscriptionA->id])
        ->and($query->pluck('id')->all())->not->toContain($subscriptionB->id);

    // Company A's user cannot open Company B's subscription record.
    $this->get('/dashboard/webhook-subscriptions/'.$subscriptionB->id.'/edit')->assertNotFound();
});
