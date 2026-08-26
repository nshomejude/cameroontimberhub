<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\RfqList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Writes to the visitor's session RFQ shortlist. The detail page's "Add to RFQ
 * List" button posts here; /request-quote reads the list back.
 */
class RfqListController extends Controller
{
    public function store(Request $request, string $slug, RfqList $list): RedirectResponse
    {
        $product = $this->visibleProduct($slug);

        $added = $list->toggle($product);

        return back()->with('status', $added
            ? $product->name.' added to your RFQ list.'
            : $product->name.' removed from your RFQ list.');
    }

    public function destroy(string $slug, RfqList $list): RedirectResponse
    {
        $list->remove($this->visibleProduct($slug));

        return back()->with('status', 'Removed from your RFQ list.');
    }

    private function visibleProduct(string $slug): Product
    {
        return Product::query()
            ->where('slug', $slug)
            ->where('status', ProductStatus::Active)
            ->whereHas('company', fn ($c) => $c->publiclyVisible())
            ->firstOrFail();
    }
}
