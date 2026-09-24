<?php

namespace App\Http\Resources\Api\V1\Concerns;

use App\Models\Favorite;
use App\Models\Follow;
use Illuminate\Http\Request;

/**
 * `is_favorited`/`is_following` on `ProductResource`/`SupplierResource` used
 * to run one `exists()` query PER RESOURCE INSTANCE, which broke this
 * codebase's bounded-query-count guarantee for listing endpoints
 * (`CatalogueApiTest`/`QuoteApiTest` both assert an upper bound on total
 * queries regardless of page size — see their docblocks).
 *
 * This trait fetches the caller's full set of favorited/followed ids for a
 * given subject type ONCE per request (one query each, the first time any
 * resource in the response asks), memoized on a static array keyed by
 * `spl_object_id($request)` so it never leaks between requests in a
 * long-lived worker and is naturally garbage-collected once the request
 * object itself is. Every subsequent resource instance in the same
 * collection does an in-memory `isset()` check, not a query.
 */
trait ResolvesFavoriteAndFollowState
{
    /** @var array<int, array<string, array<int, true>>> */
    private static array $favoritedIdCache = [];

    /** @var array<int, array<string, array<int, true>>> */
    private static array $followedIdCache = [];

    protected function isFavoritedBy(Request $request, string $subjectClass, int $subjectId): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $requestKey = spl_object_id($request);
        $cacheKey = $user->id.':'.$subjectClass;

        if (! isset(self::$favoritedIdCache[$requestKey][$cacheKey])) {
            self::$favoritedIdCache[$requestKey][$cacheKey] = Favorite::query()
                ->where('user_id', $user->id)
                ->where('favoritable_type', $subjectClass)
                ->pluck('favoritable_id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])
                ->all();
        }

        return isset(self::$favoritedIdCache[$requestKey][$cacheKey][$subjectId]);
    }

    protected function isFollowedBy(Request $request, string $subjectClass, int $subjectId): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        $requestKey = spl_object_id($request);
        $cacheKey = $user->id.':'.$subjectClass;

        if (! isset(self::$followedIdCache[$requestKey][$cacheKey])) {
            self::$followedIdCache[$requestKey][$cacheKey] = Follow::query()
                ->where('follower_id', $user->id)
                ->where('followable_type', $subjectClass)
                ->pluck('followable_id')
                ->mapWithKeys(fn ($id) => [(int) $id => true])
                ->all();
        }

        return isset(self::$followedIdCache[$requestKey][$cacheKey][$subjectId]);
    }
}
