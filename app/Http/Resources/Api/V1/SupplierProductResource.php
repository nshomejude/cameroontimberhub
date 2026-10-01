<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Catalog\ProductPublishingRules;
use App\Enums\ProductStatus;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use WeakMap;

/**
 * A supplier's own view of one of their products — unlike the public
 * `ProductResource`, this renders every status (draft/active/archived) and
 * every internal field a supplier is entitled to see about their own
 * listing.
 *
 * `actions` mirrors the exact same rules the exporter panel enforces today:
 *
 *  - `can_edit` — true when the caller's company role may manage listings
 *    (owner/manager, `ProductPublishingRules::canManageProducts()`), the
 *    same gate as `ProductResource::canEdit()`. There is no "locked while
 *    under review" state on the web to mirror — there is no review state at
 *    all (see {@see ProductStatus}).
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
            // Manageable photos, each with an id the mobile app passes back to
            // DELETE .../images/{id} and PATCH .../images/order: the single
            // primary image is id "primary", gallery rows (product_images)
            // use their numeric id. See SupplierProductImageController.
            'images' => $this->manageableImages(),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            'visibility' => $this->visibility(),

            'actions' => [
                'can_edit' => $canManage = ProductPublishingRules::canManageProducts($request->user(), $this->company_id),
                'can_submit' => $canManage && ! in_array($this->status, [ProductStatus::Active, ProductStatus::Archived], true),
                'can_archive' => $canManage,
            ],
        ];
    }

    /**
     * Whether buyers can actually see this listing, and if not, why. A
     * listing is public only when it is active AND its company passes
     * Company::scopePubliclyVisible().
     *
     * @return array{public: bool, missing: list<string>}
     */
    private function visibility(): array
    {
        $missing = [];
        if ($this->status !== ProductStatus::Active) {
            $missing[] = __('The product is not published');
        }

        $company = $this->company;
        if ($company !== null) {
            self::$companyGaps ??= new WeakMap;
            self::$companyGaps[$company] ??= $company->publicVisibilityGaps();
            $missing = [...$missing, ...self::$companyGaps[$company]];
        }

        return ['public' => $missing === [], 'missing' => $missing];
    }

    /**
     * @return list<array{id: int|string, url: string, alt: string, is_primary: bool}>
     */
    private function manageableImages(): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $out = [];

        $primary = $product->publicImage($product->primary_image_path);
        if ($primary !== null) {
            $out[] = ['id' => 'primary', 'url' => $primary, 'alt' => (string) $product->name, 'is_primary' => true];
        }

        foreach ($product->images as $image) {
            $url = $product->publicImage($image->path);
            if ($url !== null) {
                $out[] = ['id' => $image->getKey(), 'url' => $url, 'alt' => (string) $image->alt, 'is_primary' => false];
            }
        }

        return $out;
    }

    /**
     * Memo keyed by the (eager-loaded, shared) Company instance so a page of
     * listings computes the gaps once; a WeakMap never outlives the models.
     *
     * @var WeakMap<Company, list<string>>|null
     */
    private static ?WeakMap $companyGaps = null;
}
