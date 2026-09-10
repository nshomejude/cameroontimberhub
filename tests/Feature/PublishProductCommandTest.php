<?php

use App\Domain\Catalog\Commands\PublishProductCommand;
use App\Domain\Catalog\Events\ProductPublished;
use App\Enums\ProductStatus;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\RelayOutboxEventsJob;
use App\Models\Company;
use App\Models\OutboxEvent;
use App\Models\Product;
use App\Models\Species;
use App\Models\TimberLot;
use App\Models\WebhookSubscription;
use App\Support\Bus\CommandBus;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

/*
 * Architecture plan Phase 4 (Catalog context): PublishProductCommand is a
 * thin routing seam over the exact same Product::create()/update() Filament
 * already performs, so the assertions here are true regression-parity
 * checks against the pre-existing behaviour in
 * tests/Feature/ProductTimberLotWiringTest.php.
 */

test('dispatching PublishProductCommand to create an active product produces the same result as the existing flow', function () {
    $company = Company::factory()->create(['region' => 'East']);
    $species = Species::factory()->create();

    $data = Product::factory()->for($company)->for($species)->raw([
        'status' => ProductStatus::Active->value,
        'moq_quantity' => 25,
    ]);

    $product = app(CommandBus::class)->dispatch(new PublishProductCommand($data));

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->status)->toBe(ProductStatus::Active);

    $lot = TimberLot::query()->where('product_id', $product->id)->first();

    expect($lot)->not->toBeNull()
        ->and($lot->company_id)->toBe($company->id)
        ->and((float) $lot->quantity)->toBe(25.0);
});

test('dispatching PublishProductCommand to update a product into Active status produces the same result as a direct update', function () {
    $product = Product::factory()->create([
        'status' => ProductStatus::Draft,
        'moq_quantity' => 25,
    ]);

    expect(TimberLot::query()->where('product_id', $product->id)->exists())->toBeFalse();

    $data = $product->toArray();
    $data['status'] = ProductStatus::Active->value;

    $updated = app(CommandBus::class)->dispatch(new PublishProductCommand($data, $product->id));

    expect($updated->id)->toBe($product->id)
        ->and($updated->status)->toBe(ProductStatus::Active);

    expect(TimberLot::query()->where('product_id', $product->id)->exists())->toBeTrue();
});

test('the product.published outbox event is recorded transactionally with the product going live', function () {
    $company = Company::factory()->create();

    $product = Product::factory()->for($company)->create([
        'status' => ProductStatus::Active,
        'moq_quantity' => 25,
    ]);

    $row = OutboxEvent::query()
        ->where('event_type', 'product.published')
        ->where('aggregate_id', (string) $product->id)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->payload['product_id'])->toBe($product->id)
        ->and($row->payload['company_id'])->toBe($company->id);
});

test('a draft product does not record a product.published outbox event', function () {
    Product::factory()->create([
        'status' => ProductStatus::Draft,
        'moq_quantity' => 25,
    ]);

    expect(OutboxEvent::query()->where('event_type', 'product.published')->exists())->toBeFalse();
});

test('RelayOutboxEventsJob relays product.published to the ProductPublished domain event', function () {
    Event::fake([ProductPublished::class]);

    $row = OutboxEvent::query()->create([
        'aggregate_type' => 'Product',
        'aggregate_id' => '42',
        'event_type' => 'product.published',
        'payload' => ['product_id' => 42, 'company_id' => 7],
        'occurred_at' => now(),
        'published_at' => null,
        'attempts' => 0,
        'created_at' => now(),
    ]);

    app(RelayOutboxEventsJob::class)->handle();

    Event::assertDispatched(ProductPublished::class, fn (ProductPublished $event) => $event->productId === 42 && $event->companyId === 7);

    expect($row->fresh()->published_at)->not->toBeNull();
});

test('a webhook subscription for product.published receives a delivery dispatch', function () {
    Bus::fake();

    $company = Company::factory()->create();

    $subscription = WebhookSubscription::query()->create([
        'company_id' => $company->id,
        'url' => 'https://example.test/hook',
        'event_types' => ['product.published'],
        'secret' => 'secret',
        'is_active' => true,
    ]);

    $product = Product::factory()->for($company)->create([
        'status' => ProductStatus::Active,
        'moq_quantity' => 25,
    ]);

    (new RelayOutboxEventsJob())->handle();

    Bus::assertDispatched(DeliverWebhookJob::class, function (DeliverWebhookJob $job) use ($subscription, $product) {
        return $job->subscriptionId === $subscription->id
            && $job->eventType === 'product.published'
            && (int) $job->payload['product_id'] === $product->id;
    });
});
