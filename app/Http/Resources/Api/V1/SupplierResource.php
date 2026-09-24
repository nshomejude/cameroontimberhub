<?php

namespace App\Http\Resources\Api\V1;

use App\Http\Resources\Api\V1\Concerns\ResolvesFavoriteAndFollowState;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier card: the summary shape embedded in product and quote payloads
 * and returned by the supplier listing.
 *
 * Only ever rendered for companies that already passed
 * `Company::publiclyVisible()` upstream — this resource is a projection, not a
 * gate. Internal columns (`created_by`, verification workflow state, payment
 * instructions, private contacts, plan/subscription) are not in the list.
 *
 * This is the single contract for an embedded supplier: SupplierDetailResource
 * extends it rather than restating it, so a card rendered inside a product
 * listing and one rendered on a product detail page agree key for key. The
 * columns it reads are declared once as `Company::CARD_COLUMNS`; any query that
 * eager-loads a supplier for this resource must select that list (via
 * `Company::cardEagerLoad()`), or fields silently render as `null`.
 *
 * @mixin Company
 */
class SupplierResource extends JsonResource
{
    use ResolvesFavoriteAndFollowState;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'city' => $this->city,
            'region' => $this->region,
            'country_code' => $this->country_code,
            'logo_url' => $this->logoUrl(),
            'supplier_type' => $this->supplier_type?->value,
            'is_featured' => (bool) $this->is_featured,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'years_experience' => $this->years_experience,
            'response_rate_percent' => $this->response_rate_percent,
            'orders_completed' => $this->orders_completed,
            'rating' => $this->hasRating() ? [
                'average' => (float) $this->rating_avg,
                'count' => (int) $this->rating_count,
            ] : null,
            'products_count' => $this->whenCounted('products'),
            'species' => $this->whenLoaded(
                'species',
                fn () => $this->species->map(fn ($s) => ['slug' => $s->slug, 'common_name' => $s->common_name])->values(),
            ),
            'is_favorited' => $this->isFavoritedBy($request, Company::class, $this->id),
            'is_following' => $this->isFollowedBy($request, Company::class, $this->id),
            'followers_count' => $this->followersCount(),
        ];
    }

    /**
     * `followers_count` requires the caller's query to have eager-counted
     * the relation (`Company::query()->withCount('followers')`) or eager
     * loaded it (`->with('followers')`) — same `whenCounted()` convention
     * `RfqResource::quotes_count` already uses in this codebase. This
     * DELIBERATELY never fires a per-row query: `SupplierResource` renders
     * inside product-listing/search collections (`ProductCatalogueService`,
     * `SearchService`, `DomesticMarketplaceService`), and a per-row Follow
     * count reintroduces exactly the N+1 this field was flagged for at
     * review — `CatalogueApiTest`'s bounded-query-count test caught it.
     * Not yet eager-counted anywhere, so this honestly returns 0 until a
     * caller adds `withCount('followers')` to its Company eager-load —
     * that is a real follow-up, not a silent lie: 0 is exactly what is
     * knowable without the extra query this method refuses to run.
     */
    private function followersCount(): int
    {
        if ($this->resource->relationLoaded('followers')) {
            return $this->followers->count();
        }

        return (int) ($this->resource->getAttributes()['followers_count'] ?? 0);
    }
}
