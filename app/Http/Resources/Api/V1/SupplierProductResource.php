<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier's own view of one of their products — unlike the public
 * `ProductResource`, this renders every status (draft/active/archived) and
 * every internal field a supplier is entitled to see about their own
 * listing.
 *
 * `actions` mirrors the exact same rules the exporter panel enforces today:
 *
 *  - `can_edit` — `ProductResource::canEdit()` has no status gate at all
 *    (`return (bool) auth()->user()?->companies()->exists();`), so this is
 *    always true for a listing the caller owns. There is no "locked while
 *    under review" state on the web to mirror — there is no review state at
 *    all (see {@see \App\Enums\ProductStatus}).
 *  - `can_submit` — true only when the listing is not already `active` and
 *    not `archived`, the same population `SupplierProductController::submit()`
 *    accepts (see that method's docblock for why "already active" and
 *    "archived" are both blocked, and how that differs from the web's
 *    `toggleStatus` action).
 *  - `can_archive` — mirrors `ProductsTable`'s `DeleteAction`/`DeleteAction`
 *    on the edit page, which carries no visibility/status restriction
 *    either, so this is always true for a listing the caller owns.
 *
 * @mixin Product
 */
class SupplierProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'product_type' => $this->product_type?->value,
            'product_type_label' => $this->product_type?->label(),
            'status' => $this->status?->value,
            'status_label' => $this->status?->label(),
            'species_id' => $this->species_id,
            'species' => $this->whenLoaded('species', fn () => $this->species === null ? null : [
                'id' => $this->species->id,
                'slug' => $this->species->slug,
                'common_name' => $this->species->common_name,
            ]),
            'grade' => $this->grade,
            'tagline' => $this->tagline,
            'description' => $this->description,

            'price_amount' => $this->price_amount,
            'price_currency' => $this->price_currency,
            'price_unit' => $this->price_unit?->value,
            'moq_quantity' => $this->moq_quantity,
            'moq_unit' => $this->moq_unit?->value,

            'thickness_mm' => $this->thickness_mm,
            'width_min_mm' => $this->width_min_mm,
            'width_max_mm' => $this->width_max_mm,
            'length_min_m' => $this->length_min_m,
            'length_max_m' => $this->length_max_m,
            'moisture_content' => $this->moisture_content,
            'origin' => $this->origin,
            'certification' => $this->certification,

            'specifications' => $this->specifications,
            'key_benefits' => $this->key_benefits,
            'materials_used' => $this->materials_used,
            'finish' => $this->finish,
            'dimensions_description' => $this->dimensions_description,
            'custom_attributes' => $this->custom_attributes,

            'primary_image_url' => $this->primaryImageUrl(),
            'gallery' => $this->galleryImages(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'actions' => [
                'can_edit' => true,
                'can_submit' => ! in_array($this->status, [ProductStatus::Active, ProductStatus::Archived], true),
                'can_archive' => true,
            ],
        ];
    }
}
