<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SearchRequest;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\SpeciesResource;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Services\SearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Cross-entity search. One bucket is paginated at a time (`type`), with real
 * counts for all three so the client can render the tab bar. Every arm of
 * SearchService::crossSearch() re-applies the public visibility gate.
 */
class SearchController extends Controller
{
    public function __invoke(SearchRequest $request, SearchService $search): AnonymousResourceCollection
    {
        $filters = $request->filters();
        $result = $search->crossSearch($filters, $request->perPage());

        $resource = match ($filters['type']) {
            'suppliers' => SupplierResource::class,
            'species' => SpeciesResource::class,
            default => ProductResource::class,
        };

        return $resource::collection($result['results'])->additional([
            'meta' => [
                'query' => $filters['q'],
                'type' => $filters['type'],
                'counts' => $result['counts'],
                'supplier_count' => $result['supplierCount'],
                // "Did you mean" on a zero-result query, never a dead end.
                'suggestions' => $result['results']->total() === 0
                    ? $search->suggestSpecies($filters['q'])->map(fn ($s) => [
                        'slug' => $s->slug,
                        'common_name' => $s->common_name,
                    ])->values()
                    : [],
                'sort_options' => SearchService::searchSortOptions(),
            ],
        ]);
    }
}
