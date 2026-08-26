<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Models\Company;
use App\Models\Product;

/**
 * Trust figures shown on the auth screens.
 *
 * These are real aggregates, not marketing copy: the supplier count reads
 * through the same `publiclyVisible` gate the directory uses, and the product
 * count through the same `active` scope as the marketplace. A zero count is
 * returned as null so the view omits the claim entirely rather than boasting
 * about nothing.
 */
trait ProvidesAuthPageStats
{
    /** @return array{suppliers: int|null, products: int|null} */
    protected function authPageStats(): array
    {
        $suppliers = Company::query()->publiclyVisible()->count();
        $products = Product::query()->active()->count();

        return [
            'suppliers' => $suppliers > 0 ? $suppliers : null,
            'products' => $products > 0 ? $products : null,
        ];
    }
}
