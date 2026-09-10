<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Support\CameroonGeography;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The single query behind /buy-cameroon-wood — a domestic-market search that
 * never surfaces export vocabulary. Filters entirely against what already
 * exists on Product/Company today (species, grade, dimensions, quantity,
 * treatment, region/delivery). Product type stands in for a category tree
 * until gap-plan item 1.5.1 lands; see the plan's Scope Decision.
 *
 * Deliberately independent of ProductCatalogueService (the export-facing
 * /marketplace query) so the two modules never collide on a shared file.
 */
class DomesticMarketplaceService
{
    /** @return array<string, string> */
    public static function sortOptions(): array
    {
        return [
            'newest' => 'Newest listings',
            'price_low' => 'Price (low to high)',
            'price_high' => 'Price (high to low)',
            'delivery_fast' => 'Fastest delivery',
            'name' => 'Name (A–Z)',
        ];
    }

    /** Publicly visible catalogue: active listings from publicly visible suppliers. */
    public function base(): Builder
    {
        return Product::query()
            ->where('products.status', ProductStatus::Active->value)
            ->whereHas('company', fn ($c) => $c->publiclyVisible());
    }

    /** @param array<string, mixed> $filters */
    public function search(array $filters, int $perPage = 12): LengthAwarePaginator|Collection
    {
        $query = $this->query($filters)
            ->with([Company::cardEagerLoad(), 'species:id,slug,common_name', 'images']);

        if ($perPage <= 0) {
            return $query->get();
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /** @param array<string, mixed> $filters */
    public function query(array $filters): Builder
    {
        $query = $this->applyFilters($this->base(), $filters);

        return match ($filters['sort'] ?? 'newest') {
            'price_low' => $query->orderByRaw('price_amount ASC NULLS LAST')->orderBy('name'),
            'price_high' => $query->orderByRaw('price_amount DESC NULLS LAST')->orderBy('name'),
            'delivery_fast' => $query->join('companies', 'companies.id', '=', 'products.company_id')
                ->orderByRaw('companies.delivery_days_min ASC NULLS LAST')
                ->select('products.*'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('created_at'),
        };
    }

    /** @param array<string, mixed> $filters */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $region = (string) ($filters['region'] ?? '');
        $city = (string) ($filters['city'] ?? '');
        $grade = trim((string) ($filters['grade'] ?? ''));
        $treatment = (string) ($filters['treatment'] ?? '');
        $types = array_values(array_intersect((array) ($filters['types'] ?? []), array_column(ProductType::cases(), 'value')));
        $speciesIn = array_values(array_filter((array) ($filters['species'] ?? [])));
        $minQuantity = $filters['minQuantity'] ?? null;
        $maxThicknessMm = $filters['maxThicknessMm'] ?? null;

        return $query
            ->when($q !== '', fn (Builder $b) => $b->whereRaw("products.search_vector @@ plainto_tsquery('english', ?)", [$q]))
            ->when($region !== '', fn (Builder $b) => $b->whereHas('company', fn ($c) => $c->where('region', $region)))
            ->when($city !== '', fn (Builder $b) => $b->whereHas('company', fn ($c) => $c->where('city', $city)))
            ->when($grade !== '', fn (Builder $b) => $b->where('products.grade', $grade))
            ->when($treatment !== '', fn (Builder $b) => $b->where('products.moisture_content', 'ilike', "%{$treatment}%"))
            ->when($types !== [], fn (Builder $b) => $b->whereIn('products.product_type', $types))
            ->when($speciesIn !== [], fn (Builder $b) => $b->whereHas('species', fn ($s) => $s->whereIn('species.slug', $speciesIn)))
            ->when(is_numeric($minQuantity), fn (Builder $b) => $b->where(function ($w) use ($minQuantity) {
                $w->whereNull('products.moq_quantity')->orWhere('products.moq_quantity', '<=', $minQuantity);
            }))
            ->when(is_numeric($maxThicknessMm), fn (Builder $b) => $b->where('products.thickness_mm', '<=', $maxThicknessMm));
    }

    /** @return list<array{value: string, label: string, count: int}> */
    public function typeFacets(array $filters): array
    {
        $counts = $this->applyFilters($this->base(), array_diff_key($filters, ['types' => true]))
            ->selectRaw('products.product_type, count(*) as aggregate')
            ->groupBy('products.product_type')
            ->pluck('aggregate', 'product_type');

        return collect(ProductType::cases())
            ->map(fn (ProductType $t) => ['value' => $t->value, 'label' => $t->label(), 'count' => (int) ($counts[$t->value] ?? 0)])
            ->filter(fn (array $f) => $f['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * All 10 Cameroon regions, always -- not just the ones with listings
     * today. Counts are overlaid from live data where present, 0 otherwise,
     * so the filter is never missing a region just because nobody has
     * listed there yet.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function regionFacets(): array
    {
        $counts = $this->base()
            ->join('companies', 'companies.id', '=', 'products.company_id')
            ->whereNotNull('companies.region')
            ->selectRaw('companies.region, count(*) as aggregate')
            ->groupBy('companies.region')
            ->pluck('aggregate', 'region');

        return collect(CameroonGeography::regionNames())
            ->map(fn (string $region) => ['value' => $region, 'label' => $region, 'count' => (int) ($counts[$region] ?? 0)])
            ->all();
    }

    /**
     * Every city/town in Cameroon, grouped by region, for the location
     * sidebar's city select -- again independent of whether anyone has
     * listed there yet.
     *
     * @return array<string, list<string>>
     */
    public function cityOptionsByRegion(): array
    {
        return collect(CameroonGeography::regions())
            ->map(fn (array $data) => $data['cities'])
            ->all();
    }
}
