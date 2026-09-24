<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** `/api/v1/notifications/preferences` — the caller's own channel/type toggles. */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $pref = NotificationPreference::forUser($request->user());

        return response()->json(['data' => $this->present($pref)]);
    }

    /** Partial update: only the keys given are touched; unknown keys 422. */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channels' => ['sometimes', 'array'],
            'channels.*' => ['boolean'],
            'types' => ['sometimes', 'array'],
            'types.*' => ['boolean'],
        ]);

        foreach (array_keys($validated['channels'] ?? []) as $key) {
            if (! in_array($key, NotificationPreference::CHANNEL_KEYS, true)) {
                return response()->json([
                    'message' => 'Unknown preference key.',
                    'errors' => ['channels.'.$key => ["Unknown channel key \"{$key}\"."]],
                ], 422);
            }
        }

        foreach (array_keys($validated['types'] ?? []) as $key) {
            if (! in_array($key, NotificationPreference::TYPE_KEYS, true)) {
                return response()->json([
                    'message' => 'Unknown preference key.',
                    'errors' => ['types.'.$key => ["Unknown type key \"{$key}\"."]],
                ], 422);
            }
        }

        $pref = NotificationPreference::forUser($request->user());

        $pref->forceFill([
            'channels' => array_merge($pref->channels ?? [], $validated['channels'] ?? []),
            'types' => array_merge($pref->types ?? [], $validated['types'] ?? []),
        ])->save();

        return response()->json(['data' => $this->present($pref->refresh())]);
    }

    /** @return array<string, mixed> */
    private function present(NotificationPreference $pref): array
    {
        return [
            'channels' => $pref->channels,
            'types' => $pref->types,
        ];
    }
}
