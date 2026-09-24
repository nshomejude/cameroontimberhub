<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\SupplierResource;
use App\Models\Company;
use App\Models\Favorite;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A buyer/supplier bookmarking a Product or a Company. Behind `auth:sanctum`
 * (routes/api.php) — a guest never reaches here; `is_favorited` on
 * ProductResource/SupplierResource stays `false` for a guest independently of
 * this controller, computed at render time from `$request->user()`.
 *
 * `type` is resolved against an explicit allow-list, never a raw class-name
 * lookup, so an attacker cannot favorite an arbitrary Eloquent model by
 * guessing a `type` string.
 */
class FavoriteController extends Controller
{
    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    private const TYPES = [
        'product' => Product::class,
        'company' => Company::class,
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:product,company'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $modelClass = self::TYPES[$data['type']];
        $perPage = (int) ($data['per_page'] ?? 15);

        $ids = Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('favoritable_type', $modelClass)
            ->orderByDesc('id')
            ->pluck('favoritable_id');

        $itemsQuery = $modelClass::query()->whereIn('id', $ids);

        if ($data['type'] === 'product') {
            $itemsQuery->with('company');
        }

        // Preserve favorited-most-recent ordering rather than the model's
        // default id-ascending order.
        $orderedIds = $ids->values()->all();
        $items = $itemsQuery->get()->sortBy(fn ($m) => array_search($m->id, $orderedIds, true))->values();

        $page = (int) $request->query('page', 1);
        $paginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        $resourceClass = $data['type'] === 'product' ? ProductResource::class : SupplierResource::class;

        return $resourceClass::collection($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $target = $this->resolveTarget($request);

        Favorite::query()->firstOrCreate([
            'user_id' => $request->user()->id,
            'favoritable_type' => $target::class,
            'favoritable_id' => $target->id,
        ]);

        return response()->json(['favorited' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $target = $this->resolveTarget($request);

        Favorite::query()
            ->where('user_id', $request->user()->id)
            ->where('favoritable_type', $target::class)
            ->where('favoritable_id', $target->id)
            ->delete();

        return response()->json(['favorited' => false]);
    }

    private function resolveTarget(Request $request): Product|Company
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:product,company'],
            'id' => ['required', 'integer'],
        ]);

        $modelClass = self::TYPES[$data['type']];

        $target = $modelClass::query()->find($data['id']);

        if (! $target) {
            throw new NotFoundHttpException('The selected item does not exist.');
        }

        return $target;
    }
}
