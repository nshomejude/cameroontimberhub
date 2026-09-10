<?php

namespace App\Domain\Catalog\Queries;

use App\Support\Bus\Query;

/**
 * The public product marketplace search/filter — backs the API's
 * `GET /api/v1/products` (Api\V1\ProductController::index) and mirrors the
 * web catalogue's own read path.
 *
 * `$filters` is the array shape ProductCatalogueService expects
 * (see Http\Requests\Api\V1\ProductIndexRequest::filters()). Visibility
 * (active products belonging to publicly-visible companies) is NOT decided
 * here — it lives in ProductCatalogueService::base(), which the handler
 * delegates to unchanged.
 */
final class SearchProductCatalogueQuery implements Query
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public readonly array $filters,
        public readonly int $perPage = 12,
    ) {}
}
