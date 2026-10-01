<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CheckpointUpdate;
use App\Models\Shipment;
use App\Services\ShipmentWaybillQrCodeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shipment as seen by its supplier or carrier (logistics core loop) —
 * the operator-side counterpart of the buyer's ShipmentTrackingResource.
 * Operators are the ones recording checkpoints, so unlike the buyer view
 * this includes coordinates and `client_event_id` (for offline-replay
 * reconciliation on the device).
 *
 * `waybill_url` is the public, printable waybill page (always present);
 * `tracking_url` is the public tracking link, which only exists once the
 * first checkpoint has minted a tracking token — null until then.
 *
 * @mixin Shipment
 */
class SupplierShipmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $current = $this->latestCheckpoint();
        $token = $current?->tracking_token;

        return [
            'id' => $this->id,
            'waybill_number' => $this->waybill_number,
            'order_reference' => $this->order?->reference_code,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'carrier_company' => $this->carrierCompany ? [
                'id' => $this->carrierCompany->id,
                'name' => $this->carrierCompany->name,
            ] : null,
            'vehicle' => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'registration_number' => $this->vehicle->registration_number,
            ] : null,
            'driver' => $this->driver ? [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
            ] : null,
            'waybill_url' => app(ShipmentWaybillQrCodeService::class)->waybillUrl($this->resource),
            'tracking_url' => $token ? route('checkpoints.track', $token) : null,
            'current_status' => $current?->status->value,
            'current_status_label' => $current?->status->label(),
            'current_status_updated_at' => $current?->occurred_at?->toIso8601String(),
            'checkpoints' => $this->whenLoaded('checkpointUpdates', fn () => $this->checkpointUpdates
                ->map(fn (CheckpointUpdate $c) => SupplierShipmentResource::checkpoint($c))
                ->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function checkpoint(CheckpointUpdate $c): array
    {
        return [
            'id' => $c->id,
            'client_event_id' => $c->client_event_id,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'location' => $c->location,
            'latitude' => $c->latitude,
            'longitude' => $c->longitude,
            'notes' => $c->notes,
            'occurred_at' => $c->occurred_at?->toIso8601String(),
            'recorded_at' => $c->created_at?->toIso8601String(),
        ];
    }
}
