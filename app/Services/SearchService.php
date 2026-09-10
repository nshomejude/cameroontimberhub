<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * PostgreSQL FTS + Eloquent filter chain for the public company directory.
 * Centralises the search query so Livewire, controllers, and the API can
 * all produce identical results from the same inputs.
 */
class SearchService
{
    /**
     * @param  array{q?: string, region?: string, species?: string, market?: string, types?: list<string>, specs?: list<string>, speciesIn?: list<string>, sort?: string}  $filters
     */
    public function searchCompanies(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        return $this->companyQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * The single directory query. Livewire (interactive filtering) and the
     * controller (server-rendered JSON-LD) both read through it so the page's
     * structured data always describes exactly what the visitor sees.
     *
     * @param  array{q?: string, region?: string, species?: string, market?: string, types?: list<string>, specs?: list<string>, speciesIn?: list<string>, sort?: string}  $filters
     */
    public function companyQuery(array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $region = (string) ($filters['region'] ?? '');
        $species = (string) ($filters['species'] ?? '');
        $market = (string) ($filters['market'] ?? '');

        $query = Company::publiclyVisible()
            ->with([
                'species:id,slug,common_name',
                'exportMarkets:id,company_id,country_code',
                // Drives the card's comma-separated specialisation line.
                'products' => fn ($p) => $p->active()->select('id', 'company_id', 'product_type'),
            ])
            ->withCount(['products' => fn ($p) => $p->active()])
            ->ofSupplierType(array_values((array) ($filters['types'] ?? [])))
            ->handlingSpecies(array_values((array) ($filters['speciesIn'] ?? [])))
            ->withSpecialisation(array_values((array) ($filters['specs'] ?? [])))
            ->withCertifications(array_values((array) ($filters['certs'] ?? [])))
            ->minExperience(($filters['minYears'] ?? null) === null ? null : (int) $filters['minYears'])
            ->when($region !== '', fn ($query) => $query->where('region', $region))
            ->when($species !== '', fn ($query) => $query->whereHas('species', fn ($s) => $s->where('species.slug', $species)))
            ->when($market !== '', fn ($query) => $query->whereHas('exportMarkets', fn ($m) => $m->where('country_code', $market)))
            ->when($q !== '', fn ($query) => $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q]));

        return match ($filters['sort'] ?? 'featured') {
            'name' => $query->orderBy('legal_name'),
            'newest' => $query->orderByDesc('verified_at'),
            'response' => $query->orderByRaw('response_rate_percent DESC NULLS LAST')->orderByDesc('verified_at'),
            'experience' => $query->orderByRaw('years_experience DESC NULLS LAST')->orderByDesc('verified_at'),
            default => $query->orderByDesc('is_featured')->orderByDesc('verified_at'),
        };
    }

    /**
     * Bare publicly-visible company query narrowed only by free text + region.
     * Facet counts are computed from this so ticking one checkbox in a list
     * does not zero out its siblings.
     */
    public function companyFacetBase(string $q = '', string $region = ''): Builder
    {
        $q = trim($q);

        return Company::publiclyVisible()
            ->when($region !== '', fn ($query) => $query->where('region', $region))
            ->when($q !== '', fn ($query) => $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q]));
    }

    /** Sort options exposed by the directory toolbar. @return array<string, string> */
    public static function companySortOptions(): array
    {
        return [
            'featured' => 'Featured',
            'name' => 'Name (A–Z)',
            'newest' => 'Recently verified',
            'response' => 'Response rate',
            'experience' => 'Experience',
        ];
    }

    /**
     * Public marketplace catalogue query. Only `active` products belonging to
     * publicly visible companies surface here.
     *
     * @param  array{q?: string, species?: string, type?: string}  $filters
     */
    public function searchProducts(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        return $this->productQuery($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array{q?: string, species?: string, type?: string}  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, Product>
     */
    public function topProducts(array $filters, int $limit = 8)
    {
        return $this->productQuery($filters)->limit($limit)->get();
    }

    /** @param array{q?: string, species?: string, type?: string} $filters */
    private function productQuery(array $filters): Builder
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $species = (string) ($filters['species'] ?? '');
        $type = (string) ($filters['type'] ?? '');

        return Product::query()
            ->active()
            ->with([Company::cardEagerLoad(), 'species:id,slug,common_name', 'images'])
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->when($species !== '', fn ($query) => $query->whereHas('species', fn ($s) => $s->where('slug', $species)))
            ->when($type !== '', fn ($query) => $query->where('product_type', $type))
            ->when($q !== '', fn ($query) => $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q]))
            ->orderByDesc('is_featured')
            ->orderByDesc('is_best_seller')
            ->orderByDesc('created_at');
    }

    /** Result buckets offered by the /search type filter. */
    public const RESULT_TYPES = ['products', 'suppliers', 'species'];

    /**
     * Match on FTS, falling back to trigram similarity so a misspelled query
     * ("sapeli") still finds the record.
     *
     * Both arms are index-backed and the planner combines them with a
     * BitmapOr across the GIN index on search_vector and the gin_trgm_ops
     * index on $column. Note the trigram arm must use the `%` operator:
     * `similarity(col, ?) > 0.25` is NOT indexable and degrades to a
     * sequential scan, which also costs the FTS arm its index.
     */
    private function matches(Builder $query, string $column, string $q): Builder
    {
        return $query->where(fn (Builder $w) => $w
            ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q])
            ->orWhereRaw("{$column} % ?", [$q]));
    }

    /** Relevance score: the better of the FTS rank and the trigram similarity. */
    private function relevance(string $column, string $q): array
    {
        return ["GREATEST(ts_rank(search_vector, plainto_tsquery('english', ?)), similarity({$column}, ?))", [$q, $q]];
    }

    /**
     * Ranked, paginated cross-entity search backing /search.
     *
     * Every arm re-applies the public visibility gate — only `active` products
     * belonging to publicly visible companies, `verified` companies and
     * `published` species can surface. Draft and hidden records must never
     * leak through search.
     *
     * @param  array{q?: string, type?: string, species?: string, product_type?: string, grade?: string, country?: string, sort?: string}  $filters
     * @return array{results: LengthAwarePaginator, counts: array<string, int>, supplierCount: int}
     */
    public function crossSearch(array $filters, int $perPage = 12): array
    {
        $q = trim((string) ($filters['q'] ?? ''));
        $type = in_array($filters['type'] ?? '', self::RESULT_TYPES, true) ? $filters['type'] : 'products';

        $counts = [
            'products' => (clone $this->crossProductQuery($q, $filters))->toBase()->getCountForPagination(),
            'suppliers' => (clone $this->crossCompanyQuery($q))->toBase()->getCountForPagination(),
            'species' => (clone $this->crossSpeciesQuery($q))->toBase()->getCountForPagination(),
        ];

        $query = match ($type) {
            'suppliers' => $this->crossCompanyQuery($q),
            'species' => $this->crossSpeciesQuery($q),
            default => $this->crossProductQuery($q, $filters),
        };

        return [
            'results' => $query->paginate($perPage)->withQueryString(),
            'counts' => $counts,
            // "245 products from 68 suppliers" — distinct suppliers behind the hits.
            'supplierCount' => $type === 'products'
                ? (clone $this->crossProductQuery($q, $filters))->distinct()->count('products.company_id')
                : 0,
        ];
    }

    /** @param array{species?: string, product_type?: string, grade?: string, country?: string, sort?: string} $filters */
    private function crossProductQuery(string $q, array $filters): Builder
    {
        $query = Product::query()
            ->active()
            ->with([Company::cardEagerLoad(), 'species:id,slug,common_name', 'images'])
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->when(($filters['species'] ?? '') !== '', fn ($b) => $b->whereHas('species', fn ($s) => $s->where('slug', $filters['species'])))
            ->when(($filters['product_type'] ?? '') !== '', fn ($b) => $b->where('product_type', $filters['product_type']))
            ->when(($filters['grade'] ?? '') !== '', fn ($b) => $b->where('grade', $filters['grade']))
            ->when(($filters['country'] ?? '') !== '', fn ($b) => $b->whereHas('company', fn ($c) => $c->where('region', $filters['country'])));

        if ($q !== '') {
            $this->matches($query, 'name', $q);
        }

        return $this->applyCrossSort($query, $q, 'name', $filters['sort'] ?? 'relevance');
    }

    private function crossCompanyQuery(string $q): Builder
    {
        $query = Company::publiclyVisible()->with('species:id,slug,common_name')->withCount(['products' => fn ($p) => $p->active()]);

        if ($q !== '') {
            $this->matches($query, 'legal_name', $q);
        }

        return $q === ''
            ? $query->orderByDesc('is_featured')->orderByDesc('verified_at')
            : $query->orderByRaw($this->relevance('legal_name', $q)[0].' DESC', $this->relevance('legal_name', $q)[1]);
    }

    private function crossSpeciesQuery(string $q): Builder
    {
        $query = Species::published();

        if ($q !== '') {
            $this->matches($query, 'common_name', $q);
        }

        return $q === ''
            ? $query->orderBy('sort_order')->orderBy('common_name')
            : $query->orderByRaw($this->relevance('common_name', $q)[0].' DESC', $this->relevance('common_name', $q)[1]);
    }

    private function applyCrossSort(Builder $query, string $q, string $column, string $sort): Builder
    {
        return match ($sort) {
            'price_asc' => $query->orderByRaw('price_amount ASC NULLS LAST'),
            'price_desc' => $query->orderByRaw('price_amount DESC NULLS LAST'),
            'newest' => $query->orderByDesc('created_at'),
            default => $q === ''
                ? $query->orderByDesc('is_featured')->orderByDesc('is_best_seller')->orderByDesc('created_at')
                : $query->orderByRaw($this->relevance($column, $q)[0].' DESC', $this->relevance($column, $q)[1]),
        };
    }

    /** Sort options exposed by the search toolbar. @return array<string, string> */
    public static function searchSortOptions(): array
    {
        return [
            'relevance' => 'Relevance',
            'price_asc' => 'Price (low to high)',
            'price_desc' => 'Price (high to low)',
            'newest' => 'Newest',
        ];
    }

    /**
     * Nearest species by trigram similarity — powers "did you mean" on a
     * zero-result page rather than a dead end.
     *
     * @return Collection<int, Species>
     */
    public function suggestSpecies(string $q, int $limit = 6): Collection
    {
        $q = trim($q);

        if ($q === '') {
            return collect();
        }

        return Species::published()
            ->whereRaw('similarity(common_name, ?) > ?', [$q, 0.1])
            ->orderByRaw('similarity(common_name, ?) DESC', [$q])
            ->limit($limit)
            ->get();
    }

    /**
     * Cross-entity search backing /search: products, companies and species.
     *
     * @return array{products: Collection<int, Product>, companies: Collection<int, Company>, species: Collection<int, Species>}
     */
    public function searchAll(string $q, int $limit = 12): array
    {
        $q = trim($q);

        if ($q === '') {
            return ['products' => collect(), 'companies' => collect(), 'species' => collect()];
        }

        return [
            'products' => $this->topProducts(['q' => $q], $limit),
            'companies' => Company::publiclyVisible()
                ->with('species:id,slug,common_name')
                ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q])
                ->orderByDesc('is_featured')
                ->limit($limit)
                ->get(),
            'species' => Species::published()
                ->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q])
                ->orderBy('common_name')
                ->limit($limit)
                ->get(),
        ];
    }
}
