<?php

namespace App\Services;

use App\Models\CheckpointUpdate;
use Illuminate\Support\Collection;

/**
 * The public checkpoint-tracking boundary (gap-plan 1.5.11), mirroring
 * app/Services/ReceiptVerifier.php exactly: a token lookup that is either
 * an exact match or nothing (no partial/oracle-able search), and a
 * hand-written allow-list of what a stranger holding the link is told.
 *
 * A tracking token is shared by every checkpoint row recorded for the same
 * trackable's history, so a lookup returns the whole ordered history, not a
 * single row.
 */
class CheckpointTracker
{
    /** @return Collection<int, CheckpointUpdate> */
    public function findByToken(string $token): Collection
    {
        $token = trim($token);

        if ($token === '') {
            return collect();
        }

        return CheckpointUpdate::forToken($token)->oldest('created_at')->get();
    }

    /**
     * The complete set of facts a public visitor is given per checkpoint.
     * Deliberately excludes `id`, `trackable_type`/`trackable_id` (internal
     * identity), `photo_path` (the disk path is never disclosed — only
     * whether a photo exists), `recorded_by`, and raw lat/long precision
     * beyond what `location` already conveys.
     *
     * @param  Collection<int, CheckpointUpdate>  $history
     * @return array<int, array<string, mixed>>
     */
    public function publicPayload(Collection $history): array
    {
        return $history->map(fn (CheckpointUpdate $checkpoint) => [
            'status' => $checkpoint->status->label(),
            'location' => $checkpoint->location,
            'notes' => $checkpoint->notes,
            'has_photo' => $checkpoint->photo_path !== null,
            'recorded_at' => $checkpoint->created_at,
        ])->values()->all();
    }
}
