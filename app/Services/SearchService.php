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
            ->with(['company:id,slug,legal_name,trade_name,city,region', 'species:id,slug,common_name'])
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->when($species !== '', fn ($query) => $query->whereHas('species', fn ($s) => $s->where('slug', $species)))
            ->when($type !== '', fn ($query) => $query->where('product_type', $type))
            ->when($q !== '', fn ($query) => $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q]))
            ->orderByDesc('is_featured')
            ->orderByDesc('is_best_seller')
            ->orderByDesc('created_at');
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
