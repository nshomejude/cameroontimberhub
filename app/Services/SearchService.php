<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
        $q       = $filters['q'] ?? '';
        $region  = $filters['region'] ?? '';
        $species = $filters['species'] ?? '';
        $market  = $filters['market'] ?? '';

        return Company::publiclyVisible()
            ->with(['species:id,slug,common_name', 'exportMarkets:id,company_id,country_code'])
            ->when($region  !== '', fn ($query) => $query->where('region', $region))
            ->when($species !== '', fn ($query) => $query->whereHas('species', fn ($s) => $s->where('slug', $species)))
            ->when($market  !== '', fn ($query) => $query->whereHas('exportMarkets', fn ($m) => $m->where('country_code', $market)))
            ->when($q !== '', fn ($query) => $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$q]))
            ->orderByDesc('is_featured')
            ->orderByDesc('verified_at')
            ->paginate($perPage);
    }
}
