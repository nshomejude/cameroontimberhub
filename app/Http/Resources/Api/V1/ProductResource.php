<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesFavoriteAndFollowState;
use App\Models\Product;
use App\Models\TimberLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A marketplace listing card.
 *
 * Only rendered for `active` products belonging to publicly visible companies —
 * that boundary is enforced by the query layer (ProductCatalogueService::base(),
 * SearchService), never here. Internal columns (`status`, `company_id`, audit
 * timestamps beyond `created_at`) stay out of the payload.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    use ResolvesFavoriteAndFollowState;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'product_type' => $this->product_type?->value,
            'product_type_label' => $this->product_type?->label(),
            'grade' => $this->grade,
            'origin' => $this->origin,
            'certification' => $this->certification,
            'price' => [
                'amount' => $this->price_amount,
                'currency' => $this->price_currency,
                'currency_label' => $this->currencyLabel(),
                'unit' => $this->price_unit?->value,
                'unit_label' => $this->price_unit?->label(),
                'label' => $this->priceLabel(),
                'indicative_usd' => $this->indicativeUsdPrice(),
            ],
            'moq' => [
                'quantity' => $this->moq_quantity,
                'unit' => $this->moq_unit?->value,
                'label' => $this->moqLabel(),
            ],
            'is_featured' => (bool) $this->is_featured,
            'is_best_seller' => (bool) $this->is_best_seller,
            'rating' => $this->hasRating() ? [
                'average' => (float) $this->rating,
                'count' => (int) $this->reviews_count,
            ] : null,
            'primary_image_url' => $this->primaryImageUrl(),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_favorited' => $this->isFavoritedBy($request, Product::class, $this->id),
            'species' => $this->whenLoaded('species', fn () => $this->species === null ? null : [
                'slug' => $this->species->slug,
                'common_name' => $this->species->common_name,
            ]),
            'supplier' => $this->whenLoaded('company', fn () => new SupplierResource($this->company)),
            'traceability' => $this->traceability(),
        ];
    }

    /**
     * Timber lots linked to this product (`Product::lots()`), for the
     * public traceability API. Only computed `$this->whenLoaded('lots')` —
     * a per-row `TimberLot` query here would reintroduce the same N+1 this
     * class's `is_favorited` field was flagged for by
     * `CatalogueApiTest`'s bounded-query-count test. `null` (never an
     * empty/fake object) when no real lot is linked or the relation was not
     * eager-loaded by the caller — this must not fabricate a traceability
     * claim for a product nothing has actually traced.
     *
     * @return array<string, mixed>|null
     */
    private function traceability(): ?array
    {
        if (! $this->resource->relationLoaded('lots')) {
            return null;
        }

        $lots = $this->lots;

        if ($lots->isEmpty()) {
            return null;
        }

        $first = $lots->first();

        return [
            'lots' => $lots->map(fn (TimberLot $lot) => [
                'lot_number' => $lot->lot_number,
                'volume_m3' => $lot->volume_m3 !== null ? (float) $lot->volume_m3 : null,
                'passport_url' => route('passport.show', $lot),
                'barcode_value' => $lot->lot_number,
            ])->values(),
            'harvest_location' => [
                'latitude' => $first->origin_latitude !== null ? (float) $first->origin_latitude : null,
                'longitude' => $first->origin_longitude !== null ? (float) $first->origin_longitude : null,
                'region' => $first->origin_region,
            ],
        ];
    }
}
