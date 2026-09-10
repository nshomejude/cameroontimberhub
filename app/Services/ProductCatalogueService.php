<?php

namespace App\Services;

use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single query behind the public marketplace (/marketplace).
 *
 * Mirrors SpeciesDirectoryService: every facet count is a real count over the
 * publicly visible catalogue, computed with the same predicate that filtering
 * uses — so a count can never disagree with the results it produces. A facet
 * whose count is zero is dropped rather than rendered as an empty promise.
 */
class ProductCatalogueService
{
    /** @return array<string, string> */
    public static function sortOptions(): array
    {
        return [
            'featured' => 'Featured',
            'price_low' => 'Price (low to high)',
            'price_high' => 'Price (high to low)',
            'newest' => 'Newest listings',
            'name' => 'Name (A–Z)',
        ];
    }

    /**
     * Publicly visible catalogue: active listings from publicly visible
     * suppliers. Every facet and every result set reads through this.
     */
    public function base(): Builder
    {
        // Columns are qualified: the facet queries join `companies` / `species`,
        // both of which also have `status` / `slug` columns.
        return Product::query()
            ->where('products.status', ProductStatus::Active->value)
            ->whereHas('company', fn ($c) => $c->publiclyVisible());
    }

    /** @param array<string, mixed> $filters */
    public function search(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        return $this->query($filters)
            ->with([Company::cardEagerLoad(), 'species:id,slug,common_name', 'images'])
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Facet groups AND together; values inside one group OR together — the
     * conventional faceted-browse semantics.
     *
     * @param  array{q?: string, supplier?: string, types?: list<string>, speciesIn?: list<string>, region?: string, certifiedOnly?: bool, inStock?: bool, sort?: string}  $filters
     */
    public function query(array $filters): Builder
    {
        $query = $this->applyFilters($this->base(), $filters);

        return match ($filters['sort'] ?? 'featured') {
            'price_low' => $query->orderByRaw('price_amount ASC NULLS LAST')->orderBy('name'),
            'price_high' => $query->orderByRaw('price_amount DESC NULLS LAST')->orderBy('name'),
            'newest' => $query->orderByDesc('created_at'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('is_featured')->orderByDesc('is_best_seller')->orderByDesc('created_at'),
        };
    }

    /** @param array<string, mixed> $filters */
    public function applyFilters(Builder $query, array $filters, ?string $skipGroup = null): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $region = (string) ($filters['region'] ?? '');
        $types = array_values(array_intersect((array) ($filters['types'] ?? []), array_column(ProductType::cases(), 'value')));
        $speciesIn = array_values(array_filter((array) ($filters['speciesIn'] ?? [])));

        $supplier = trim((string) ($filters['supplier'] ?? ''));

        return $query
            // Qualified: the facet queries join `species`, which has a
            // `search_vector` column of its own, so a bare reference here is
            // ambiguous the moment free text and a facet count meet.
            ->when($q !== '', fn (Builder $b) => $b->whereRaw("products.search_vector @@ plainto_tsquery('english', ?)", [$q]))
            ->when($supplier !== '', fn (Builder $b) => $b->whereHas('company', fn ($c) => $c->where('companies.slug', $supplier)))
            ->when($region !== '', fn (Builder $b) => $b->whereHas('company', fn ($c) => $c->where('region', $region)))
            ->when($skipGroup !== 'types' && $types !== [], fn (Builder $b) => $b->whereIn('products.product_type', $types))
            ->when($skipGroup !== 'species' && $speciesIn !== [], fn (Builder $b) => $b->whereHas('species', fn ($s) => $s->whereIn('species.slug', $speciesIn)))
            ->when($skipGroup !== 'flags' && ! empty($filters['certifiedOnly']), fn (Builder $b) => $b->whereNotNull('products.certification'))
            ->when($skipGroup !== 'flags' && ! empty($filters['bestSellers']), fn (Builder $b) => $b->where('products.is_best_seller', true));
    }

    /**
     * Product-type facet. Counted against everything except the type filter
     * itself, so ticking one type does not zero out its siblings.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{value: string, label: string, count: int}>
     */
    public function typeFacets(array $filters): array
    {
        $counts = $this->applyFilters($this->base(), $filters, skipGroup: 'types')
            ->select('products.product_type', DB::raw('count(*) as aggregate'))
            ->groupBy('products.product_type')
            ->pluck('aggregate', 'product_type');

        return collect(ProductType::cases())
            ->map(fn (ProductType $t) => [
                'value' => $t->value,
                'label' => $t->label(),
                'count' => (int) ($counts[$t->value] ?? 0),
            ])
            ->filter(fn (array $f) => $f['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Species facet, over the species actually represented in the catalogue.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{value: string, label: string, count: int}>
     */
    public function speciesFacets(array $filters): array
    {
        return $this->applyFilters($this->base(), $filters, skipGroup: 'species')
            ->join('species', 'species.id', '=', 'products.species_id')
            ->select('species.slug', 'species.common_name', DB::raw('count(*) as aggregate'))
            ->groupBy('species.slug', 'species.common_name')
            ->orderByDesc('aggregate')
            ->orderBy('species.common_name')
            ->get()
            ->map(fn ($row) => ['value' => $row->slug, 'label' => $row->common_name, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * Supplier-region facet.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public function regionFacets(): array
    {
        return $this->base()
            ->join('companies', 'companies.id', '=', 'products.company_id')
            ->whereNotNull('companies.region')
            ->select('companies.region', DB::raw('count(*) as aggregate'))
            ->groupBy('companies.region')
            ->orderBy('companies.region')
            ->get()
            ->map(fn ($row) => ['value' => $row->region, 'label' => $row->region, 'count' => (int) $row->aggregate])
            ->all();
    }

    /**
     * Boolean facets rendered as single checkboxes ("Certified", "Best sellers").
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<string, int>
     */
    public function flagFacets(array $filters): Collection
    {
        $base = fn () => $this->applyFilters($this->base(), $filters, skipGroup: 'flags');

        return collect([
            'certifiedOnly' => (int) $base()->whereNotNull('products.certification')->count(),
            'bestSellers' => (int) $base()->where('products.is_best_seller', true)->count(),
        ]);
    }
}
