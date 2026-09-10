<?php

namespace App\Domain\Catalog\Queries;

use App\Models\Product;
use App\Models\User;
use App\Support\Bus\HandlesQuery;
use App\Support\Bus\Query;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company-scoped supplier product query. Byte-identical to the scoping
 * ProductResource::getEloquentQuery() applied inline: `Product::query()`
 * (the SoftDeletes global scope is applied automatically, exactly as
 * `parent::getEloquentQuery()` gave it) narrowed to the dashboard-owned
 * companies of the acting user, or `1 = 0` when unauthenticated. No table
 * filters/sorts/pagination here — Filament adds those on top of the
 * returned Builder.
 */
final class ListSupplierProductsHandler implements HandlesQuery
{
    public function handle(Query $query): Builder
    {
        /** @var ListSupplierProductsQuery $query */
        $user = $query->userId ? User::find($query->userId) : null;

        return Product::query()->when(
            $user,
            fn (Builder $q) => $q->whereHas('company', fn (Builder $c) => $c->dashboardOwned($user)),
            fn (Builder $q) => $q->whereRaw('1 = 0'),
        );
    }
}
