<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Follow;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Things I follow" — real events from companies the caller follows.
 *
 * LIMITATION (deliberate, per brief): nothing in this codebase currently
 * emits a "follow feed" event. `Product` has no status-transition-history
 * column (no `activated_at`/status-audit table), so a published product's
 * `created_at` is the only real, verifiable timestamp available for "this
 * product went live" — this feed uses that rather than inventing a
 * transition-timestamp column. `Announcement` (routes/api.php's
 * `AnnouncementController`) has NO `company_id`/publisher concept
 * (app/Models/Announcement.php — `createdBy` is a staff `User`, not a
 * company), so it is deliberately left OUT of this feed rather than forcing
 * a fake `company` field onto it. When more real event sources exist
 * (order/review/badge events from a followed company, a genuine Announcement
 * publisher link), they can be unioned in here — this is not a permanent
 * ceiling, just today's honest set.
 */
class FeedController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $followedCompanyIds = Follow::query()
            ->where('follower_id', $user->id)
            ->where('followable_type', Company::class)
            ->pluck('followable_id');

        $perPage = min(max((int) $request->query('per_page', 20), 1), 50);

        $products = Product::query()
            ->with('company')
            ->where('status', ProductStatus::Active)
            ->whereIn('company_id', $followedCompanyIds)
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString();

        $products->getCollection()->transform(fn (Product $product) => [
            'id' => $product->id,
            'type' => 'product',
            'company' => [
                'name' => $product->company?->name,
                'slug' => $product->company?->slug,
                'logo_url' => $product->company?->logoUrl(),
            ],
            'title' => $product->name,
            'body' => $product->grade ?? $product->origin,
            'image_url' => $product->primaryImageUrl(),
            'url_path' => '/products/'.$product->slug,
            'created_at' => $product->created_at?->toIso8601String(),
        ]);

        return response()->json($products);
    }
}
