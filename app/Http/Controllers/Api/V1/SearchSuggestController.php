<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SearchSuggestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Instant (type-ahead) search: grouped, capped, publicly visible results for
 * a partial query. Serves the mobile app search bar and the website header
 * combobox. Queries shorter than SearchSuggestService::MIN_CHARS return empty
 * groups (200, never 422) so clients can fire on every debounced keystroke.
 *
 * Throttled by its own `search-suggest` limiter (not the 60/min api-key one)
 * and cached publicly for 60s with an ETag via the `cache.headers` middleware.
 */
class SearchSuggestController extends Controller
{
    public function __invoke(Request $request, SearchSuggestService $suggest): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'types' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.SearchSuggestService::MAX_LIMIT],
        ]);

        $q = SearchSuggestService::normalize($validated['q'] ?? '');
        $types = isset($validated['types'])
            ? array_values(array_filter(array_map('trim', explode(',', $validated['types']))))
            : SearchSuggestService::TYPES;

        if (array_diff($types, SearchSuggestService::TYPES) !== []) {
            throw ValidationException::withMessages([
                'types' => __('validation.in', ['attribute' => 'types']),
            ]);
        }

        return response()->json([
            'data' => $suggest->suggest($q, $types, (int) ($validated['limit'] ?? SearchSuggestService::MAX_LIMIT)),
            'meta' => [
                'query' => $q,
                'min_chars' => SearchSuggestService::MIN_CHARS,
                'search_url' => url('/search').'?q='.rawurlencode($q),
            ],
        ]);
    }
}
