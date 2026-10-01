<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Favorite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * `/account/saved` — the buyer's saved suppliers. Reads and writes the same
 * `favorites` rows as Api\V1\FavoriteController (`favoritable_type` =
 * Company), so a supplier saved in the app shows up here and vice versa.
 *
 * Saving resolves the company through Company::publiclyVisible() by slug
 * (a hidden company cannot be bookmarked from the web); removing only ever
 * touches the signed-in user's own row.
 */
class SavedSupplierController extends Controller
{
    public function index(Request $request): View
    {
        $favorites = Favorite::query()
            ->where('user_id', $request->user()->getKey())
            ->where('favoritable_type', Company::class)
            ->with('favoritable')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $visibleIds = Company::query()->publiclyVisible()
            ->whereIn('id', collect($favorites->items())->pluck('favoritable_id'))
            ->pluck('id')
            ->all();

        return view('public.account.saved', [
            'favorites' => $favorites,
            'visibleIds' => $visibleIds,
        ]);
    }

    public function store(Request $request, string $slug): RedirectResponse
    {
        $company = Company::query()->publiclyVisible()->where('slug', $slug)->firstOrFail();

        Favorite::query()->firstOrCreate([
            'user_id' => $request->user()->getKey(),
            'favoritable_type' => Company::class,
            'favoritable_id' => $company->getKey(),
        ]);

        return back()->with('status', __('messages.account_center.supplier_saved'));
    }

    public function destroy(Request $request, string $slug): RedirectResponse
    {
        $company = Company::query()->where('slug', $slug)->firstOrFail();

        Favorite::query()
            ->where('user_id', $request->user()->getKey())
            ->where('favoritable_type', Company::class)
            ->where('favoritable_id', $company->getKey())
            ->delete();

        return back()->with('status', __('messages.account_center.supplier_removed'));
    }
}
