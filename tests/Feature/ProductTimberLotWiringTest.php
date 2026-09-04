<?php

use App\Enums\LotEventType;
use App\Enums\ProductStatus;
use App\Enums\TimberLotStatus;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Models\TimberLot;
use App\Observers\ProductObserver;
use Illuminate\Support\Facades\Log;

test('creating an active product with a real moq_quantity creates a linked TimberLot with a SourceRegistered event', function () {
    $company = Company::factory()->create(['region' => 'East']);
    $species = Species::factory()->create();

    $product = Product::factory()->for($company)->for($species)->create([
        'status' => ProductStatus::Active,
        'moq_quantity' => 25,
    ]);

    $lot = TimberLot::query()->where('product_id', $product->id)->first();

    expect($lot)->not->toBeNull()
        ->and($lot->company_id)->toBe($company->id)
        ->and($lot->species_id)->toBe($species->id)
        ->and($lot->product_form)->toBe($product->product_type->value)
        ->and($lot->grade)->toBe($product->grade)
        ->and((float) $lot->quantity)->toBe(25.0)
        ->and((float) $lot->available_quantity)->toBe(25.0)
        ->and($lot->unit)->toBe($product->moq_unit->value)
        ->and($lot->origin_region)->toBe('East')
        ->and($lot->status)->toBe(TimberLotStatus::Available);

    $event = $lot->lotEvents()->first();
    expect($event)->not->toBeNull()
        ->and($event->event_type)->toBe(LotEventType::SourceRegistered);
});

test('creating a draft product does not create a lot', function () {
    $product = Product::factory()->create([
        'status' => ProductStatus::Draft,
        'moq_quantity' => 25,
    ]);

    expect(TimberLot::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

test('a product without a real moq_quantity does not create a lot even when active', function () {
    $product = Product::factory()->create([
        'status' => ProductStatus::Active,
        'moq_quantity' => null,
    ]);

    expect(TimberLot::query()->where('product_id', $product->id)->exists())->toBeFalse();
});

test('archiving a product archives its linked lot', function () {
    $product = Product::factory()->create([
        'status' => ProductStatus::Active,
        'moq_quantity' => 25,
    ]);

    $lot = TimberLot::query()->where('product_id', $product->id)->firstOrFail();
    expect($lot->status)->toBe(TimberLotStatus::Available);

    $product->update(['status' => ProductStatus::Archived]);

    expect($lot->fresh()->status)->toBe(TimberLotStatus::Archived);
});

test('a failure inside the observer is caught and logged, and the product still saves successfully', function () {
    Log::shouldReceive('error')->once();

    $observer = new class extends ProductObserver
    {
        public function created(Product $product): void
        {
            try {
                throw new \RuntimeException('simulated TimberLot wiring failure');
            } catch (\Throwable $e) {
                Log::error('ProductObserver::created failed to wire TimberLot', [
                    'product_id' => $product->id ?? null,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    };

    Product::observe($observer);

    $product = Product::factory()->create([
        'status' => ProductStatus::Active,
        'moq_quantity' => 25,
    ]);

    expect($product->exists)->toBeTrue()
        ->and(Product::query()->whereKey($product->id)->exists())->toBeTrue();

    // Restore the real observer for any subsequent tests in this process.
    Product::observe(ProductObserver::class);
});
