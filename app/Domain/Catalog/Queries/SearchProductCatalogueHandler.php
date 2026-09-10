<?php

namespace App\Domain\Catalog\Queries;

use App\Services\ProductCatalogueService;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Thin seam over ProductCatalogueService::search() — the single place the
 * marketplace query (visibility gate, filters, sort, eager loads,
 * pagination) already lived. This handler does not duplicate any of that;
 * it only gives the controller a Query/Bus entry point so it stops calling
 * the service directly. Facet counts stay in the controller: they are
 * separate, sibling service calls, not part of this read (mirrors the Trade
 * queries leaving their controllers' other reads alone).
 */
final class SearchProductCatalogueHandler implements HandlesQuery
{
    public function __construct(private readonly ProductCatalogueService $catalogue) {}

    public function handle(Query $query): LengthAwarePaginator
    {
        /** @var SearchProductCatalogueQuery $query */
        return $this->catalogue->search($query->filters, $query->perPage);
    }
}
