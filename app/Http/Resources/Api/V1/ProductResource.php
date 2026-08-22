<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
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
            'species' => $this->whenLoaded('species', fn () => $this->species === null ? null : [
                'slug' => $this->species->slug,
                'common_name' => $this->species->common_name,
            ]),
            'supplier' => $this->whenLoaded('company', fn () => new SupplierResource($this->company)),
        ];
    }
}
