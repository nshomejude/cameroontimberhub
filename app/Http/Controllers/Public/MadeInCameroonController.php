<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Product;
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
    public function index(): View
    {
        $products = Product::query()
            ->madeInCameroon()
            ->with(['company:id,slug,legal_name,trade_name,logo_path,status', 'species:id,slug,common_name', 'images'])
            ->orderByDesc('is_featured')
            ->orderByDesc('created_at')
            ->paginate(12);

        return view('public.made-in-cameroon.index', [
            'products' => $products,
            'breadcrumbs' => [
                ['label' => 'Home', 'url' => route('home')],
                ['label' => 'Made in Cameroon', 'url' => route('made-in-cameroon')],
            ],
        ]);
    }
}
