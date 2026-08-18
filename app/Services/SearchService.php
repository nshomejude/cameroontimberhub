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
     * @param  array{q?: string, region?: string, species?: string, market?: string}  $filters
     */
    public function searchCompanies(array $filters, int $perPage = 12): LengthAwarePaginator
    {
        $q = $filters['q'] ?? '';
        $region = $filters['region'] ?? '';
        $species = $filters['species'] ?? '';
        $market = $filters['market'] ?? '';

        return Company::publiclyVisible()
            ->with(['species:id,slug,common_name', 'exportMarkets:id,company_id,country_code'])
            ->when($region !== '', fn ($query) => $query->where('region', $region))
            ->when($species !== '', fn ($query) => $query->whereHas('species', fn ($s) => $s->where('slug', $species)))
            ->when($market !== '', fn ($query) => $query->whereHas('exportMarkets', fn ($m) => $m->where('country_code', $market)))
            ->when($q !== '', fn ($query) => $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q]))
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->paginate($perPage);
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
