<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * The product detail screen: the card plus the specification table, the
 * gallery, the species-derived facts and the full supplier profile.
 *
 * @mixin Product
 */
class ProductDetailResource extends ProductResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'tagline' => $this->tagline,
            'description' => $this->description,
            'key_benefits' => $this->key_benefits,
            'width' => $this->widthLabel(),
            'length' => $this->lengthLabel(),
            'thickness_mm' => $this->thickness_mm,
            'moisture_content' => $this->moisture_content,
            'specifications' => $this->specificationRows(),
            'trust_badges' => $this->trustBadges(),
            'gallery' => $this->galleryImages(),
            // The full species record, so the client can render the timber
            // facts without a second round trip.
            'species_detail' => $this->species === null ? null : new SpeciesDetailResource($this->species),
            'supplier' => $this->whenLoaded('company', fn () => new SupplierDetailResource($this->company)),
        ]);
    }
}
