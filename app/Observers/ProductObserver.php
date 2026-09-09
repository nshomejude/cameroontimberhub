<?php

namespace App\Observers;

use App\Domain\Catalog\Events\ProductPublished;
use App\Enums\LotEventType;
use App\Enums\ProductStatus;
use App\Enums\TimberLotStatus;
use App\Models\Product;
use App\Models\TimberLot;
use App\Services\FraudDetectionService;
use App\Support\Events\RecordsOutboxEvents;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wires real Product listings into the Timber Lot system: a genuine
 * tradeable listing (active + a real moq_quantity) gets a linked TimberLot,
 * and archiving the listing archives its lot in turn.
 *
 * Also runs pricing-anomaly detection (blueprint §25) on every save — added
 * inside this observer's existing try/catch rather than as a second,
 * competing Product observer, so a fraud-detection bug shares the exact same
 * "never block the real save" guarantee as the TimberLot wiring below.
 *
 * Every entry point is wrapped in try/catch and logs-and-returns on failure:
 * a bug here must never prevent a product from being created or saved,
 * which is why this observer never lets an exception escape.
 */
class ProductObserver
{
    use RecordsOutboxEvents;
    public function created(Product $product): void
    {
        try {
            $this->maybeCreateLot($product);
            $this->detectPricingAnomaly($product);
        } catch (Throwable $e) {
            Log::error('ProductObserver::created failed to wire TimberLot', [
                'product_id' => $product->id ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function updated(Product $product): void
    {
        try {
            $this->maybeCreateLot($product);
            $this->maybeArchiveLot($product);
            $this->detectPricingAnomaly($product);
        } catch (Throwable $e) {
            Log::error('ProductObserver::updated failed to wire TimberLot', [
                'product_id' => $product->id ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Blueprint §25 pricing-anomaly detection — detection/alerting only, never blocks the save. */
    private function detectPricingAnomaly(Product $product): void
    {
        app(FraudDetectionService::class)->detectPricingAnomaly($product);
    }

    /**
     * Creates a linked TimberLot for a genuine tradeable listing (active
     * status + a real moq_quantity) when one does not already exist.
     */
    private function maybeCreateLot(Product $product): void
    {
        if ($product->status !== ProductStatus::Active) {
            return;
        }

        if ($product->moq_quantity === null || (float) $product->moq_quantity <= 0) {
            return;
        }

        if (TimberLot::query()->where('product_id', $product->id)->exists()) {
            return;
        }

        $company = $product->company;

        $lot = TimberLot::create([
            'company_id' => $product->company_id,
            'species_id' => $product->species_id,
            'product_id' => $product->id,
            'product_form' => $product->product_type?->value,
            'grade' => $product->grade,
            'quantity' => $product->moq_quantity,
            'available_quantity' => $product->moq_quantity,
            'unit' => $product->moq_unit?->value,
            'origin_region' => $company?->region,
            'status' => TimberLotStatus::Available,
        ]);

        $lot->recordEvent(LotEventType::SourceRegistered);

        // This is the exact moment a Product listing "goes live" (a genuine
        // tradeable, active listing that did not already have a linked
        // TimberLot) — formalize it as a domain event for webhook
        // subscribers (architecture plan Phase 4, Catalog context). Recorded
        // inside the same DB transaction as the state change above, since
        // this Observer callback fires while the owning save's transaction
        // (whether from CommandBus::dispatch() or Filament's default
        // create/update lifecycle) is still open.
        $this->recordOutboxEvent(new ProductPublished(
            productId: $product->id,
            companyId: $product->company_id,
        ));
    }

    /**
     * Archives the linked TimberLot (if any) when its Product is archived.
     */
    private function maybeArchiveLot(Product $product): void
    {
        if ($product->status !== ProductStatus::Archived) {
            return;
        }

        $lot = TimberLot::query()->where('product_id', $product->id)->first();

        if (! $lot || $lot->status === TimberLotStatus::Archived) {
            return;
        }

        $lot->update(['status' => TimberLotStatus::Archived]);
    }
}
