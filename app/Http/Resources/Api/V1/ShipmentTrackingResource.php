<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CheckpointUpdate;
use App\Models\Shipment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One shipment plus its checkpoint history, for the buyer mobile app's
 * order-tracking view — the token-authed counterpart to the public,
 * token-in-URL tracking page (`CheckpointTrackingController` /
 * `CheckpointTracker::publicPayload()`).
 *
 * Checkpoint disclosure deliberately mirrors `CheckpointTracker::publicPayload()`'s
 * allow-list exactly: status label, location, notes, whether a photo exists,
 * and when it happened (plus `photo_url`, the buyer-authorised photo
 * stream, and the checkpoint/shipment `id`s it needs) — never raw `latitude`/`longitude` (never disclosed
 * even to the owning buyer, same as a public tracking-link visitor), never
 * `photo_path` or `recorded_by`. The one addition over the
 * public payload is `occurred_at` alongside `recorded_at`: the buyer app
 * needs the client-reported event time (not just server receipt time) to
 * explain out-of-order syncs, whereas the public page only ever shows one.
 *
 * `current_status` comes from `Shipment::latestCheckpoint()` — the same
 * occurred_at-ordered "real world current state" used everywhere else
 * (ShipmentObserver, the public tracking page) — never merely the last
 * checkpoint by array order, which a late-arriving offline sync could make
 * wrong (see HasCheckpointUpdates::latestCheckpoint() doc block).
 *
 * @mixin Shipment
 */
class ShipmentTrackingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $current = $this->latestCheckpoint();
        $reference = $this->order?->reference_code;

        return [
            'id' => $this->id,
            'waybill_number' => $this->waybill_number,
            'carrier_status' => $this->carrier_status?->value,
            'carrier_status_label' => $this->carrier_status?->label(),
            'current_status' => $current?->status->label(),
            'current_status_updated_at' => $current?->occurred_at?->toIso8601String(),
            'checkpoints' => $this->whenLoaded(
                'checkpointUpdates',
                fn () => $this->checkpointUpdates
                    ->map(fn (CheckpointUpdate $checkpoint) => [
                        'id' => $checkpoint->id,
                        'status' => $checkpoint->status->label(),
                        'location' => $checkpoint->location,
                        'notes' => $checkpoint->notes,
                        'has_photo' => $checkpoint->photo_path !== null,
                        'photo_url' => $this->photoUrl($request, $checkpoint, $reference),
                        'occurred_at' => $checkpoint->occurred_at?->toIso8601String(),
                        'recorded_at' => $checkpoint->created_at?->toIso8601String(),
                    ])
                    ->values(),
                []
            ),
        ];
    }

    /**
     * The photo stream for the caller's side: this resource also backs the
     * supplier's `supplier/orders/{reference}/shipments`, whose callers
     * fetch photos through the supplier route, not the buyer one.
     */
    private function photoUrl(Request $request, CheckpointUpdate $checkpoint, ?string $reference): ?string
    {
        if ($checkpoint->photo_path === null) {
            return null;
        }

        if ($request->routeIs('api.v1.supplier.*')) {
            return route('api.v1.supplier.shipments.checkpoints.photo', ['shipment' => $this->id, 'checkpoint' => $checkpoint->id]);
        }

        return $reference === null ? null : route('api.v1.orders.shipments.checkpoints.photo', [
            'reference' => $reference, 'shipment' => $this->id, 'checkpoint' => $checkpoint->id,
        ]);
    }
}
