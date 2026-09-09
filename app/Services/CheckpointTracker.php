<?php

namespace App\Services;

use App\Models\CheckpointUpdate;
use Illuminate\Database\Eloquent\Model;
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
     * The single, shared checkpoint-creation path (blueprint §45-46). Any
     * feature that records a CheckpointUpdate against a trackable — the
     * offline-capable driver capture form (App\Http\Controllers\Public\LogisticsCheckpointController)
     * today, and any future caller — MUST go through this method rather than
     * calling `CheckpointUpdate::create()`/`$trackable->checkpointUpdates()->create()`
     * directly, so token issuance/reuse and the `occurred_at` default stay
     * consistent in one place and `CheckpointUpdate::created` (ShipmentObserver)
     * fires exactly the same way regardless of caller.
     *
     * Token handling: a `tracking_token` is shared by every checkpoint
     * recorded for the same trackable's history (see the checkpoint_updates
     * migration doc). This reuses the trackable's existing token if it
     * already has one, or mints a fresh unique one on the trackable's first
     * checkpoint — the same do-while-until-unique discipline as
     * OrderReferenceGenerator.
     *
     * `occurred_at`: pass the client-reported event time when known (e.g.
     * the driver's device clock at the moment they filled in the form,
     * which may be well before the sync actually reaches the server on
     * patchy connectivity). Defaults to "now" via CheckpointUpdate::booted()
     * when omitted, matching every pre-existing call site.
     *
     * @param  array<string, mixed>  $data  status, location, latitude,
     *         longitude, notes, occurred_at, recorded_by — anything else is
     *         ignored (this method itself is the allow-list of writable
     *         fields for untrusted/field-submitted input).
     */
    public function record(Model $trackable, array $data): CheckpointUpdate
    {
        $token = $trackable->checkpointUpdates()->value('tracking_token')
            ?? $this->generateUniqueToken();

        return $trackable->checkpointUpdates()->create([
            'tracking_token' => $token,
            'status' => $data['status'],
            'location' => $data['location'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'notes' => $data['notes'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
            'recorded_by' => $data['recorded_by'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? null,
        ]);
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = str()->random(48);
        } while (CheckpointUpdate::forToken($token)->exists());

        return $token;
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
