<?php

namespace App\Http\Controllers\Public;

use App\Enums\ProductType;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Species;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public landing page for the "Made in Cameroon" badge (gap-plan 1.5.7).
 * Lists listings that pass Product::qualifiesForMadeInCameroon() — a
 * computed fact, so this page always reflects live company/product state
 * with nothing to manually issue or revoke.
 *
 * NOTE: the brief also mentions a product QR showing transformation
 * history. That is explicitly OUT OF SCOPE here — it depends on
 * shipment/checkpoint tracking (gap-plan 1.5.10/1.5.11), which does not
 * exist yet. Tracked as a follow-up in docs/GAP_PLAN.md.
 */
class MadeInCameroonController extends Controller
{
    public function index(Request $request): View
    {
        $species = array_values(array_filter((array) $request->query('species', [])));
        $types = array_values(array_intersect((array) $request->query('types', []), array_column(ProductType::cases(), 'value')));

        $products = Product::query()
            ->madeInCameroon()
            ->when($species !== [], fn (Builder $q) => $q->whereHas('species', fn (Builder $s) => $s->whereIn('species.slug', $species)))
            ->when($types !== [], fn (Builder $q) => $q->whereIn('products.product_type', $types))
            ->with(['company:id,slug,legal_name,trade_name,logo_path,status', 'species:id,slug,common_name', 'images'])
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(12)
            ->withQueryString();

        return view('public.made-in-cameroon.index', [
            'products' => $products,
            'filters' => [
                'species' => $species,
                'types' => $types,
            ],
            'speciesOptions' => Species::published()->orderBy('common_name')->pluck('common_name', 'slug'),
            'typeOptions' => collect(ProductType::cases())->mapWithKeys(fn (ProductType $t) => [$t->value => $t->label()]),
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Made in Cameroon', 'url' => route('made-in-cameroon')],
            ],
        ]);
    }
}
