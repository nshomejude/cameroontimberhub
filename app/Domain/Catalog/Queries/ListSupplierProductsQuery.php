<?php

namespace App\Domain\Catalog\Queries;

use App\Support\Bus\Query;

/**
 * A supplier's own product listings, scoped to the companies the given user
 * runs from the dashboard — backs the exporter panel's ProductResource
 * (and any future supplier-facing product API).
 *
 * The handler returns an unexecuted Builder (Filament needs to keep
 * chaining its own table filters/sorts/pagination onto it), scoped exactly
 * as ProductResource::getEloquentQuery() did inline: `whereHas('company',
 * dashboardOwned($user))`, or a `1 = 0` no-op when there is no
 * authenticated user.
 */
final class ListSupplierProductsQuery implements Query
{
    public function __construct(
        public readonly ?int $userId,
    ) {}
}
