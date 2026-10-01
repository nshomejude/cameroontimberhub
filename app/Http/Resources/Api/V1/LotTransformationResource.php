<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LotTransformation;
use App\Models\TimberLot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mass-balance record where the caller's company is the processor — the
 * exporter LotTransformationResource infolist fields. Input/output lots are
 * included on the detail endpoint only (when loaded).
 *
 * @mixin LotTransformation
 */
class LotTransformationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $lot = fn (TimberLot $l) => [
            'id' => $l->id,
            'lot_number' => $l->lot_number,
            'quantity_m3' => $l->pivot?->quantity_m3 !== null ? (string) $l->pivot->quantity_m3 : null,
        ];

        return [
            'id' => $this->id,
            'transformation_type' => $this->transformation_type,
            'input_volume_m3' => $this->input_volume_m3 !== null ? (string) $this->input_volume_m3 : null,
            'output_volume_m3' => $this->output_volume_m3 !== null ? (string) $this->output_volume_m3 : null,
            'loss_volume_m3' => $this->loss_volume_m3 !== null ? (string) $this->loss_volume_m3 : null,
            'transformation_ratio' => $this->transformation_ratio !== null ? (string) $this->transformation_ratio : null,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'notes' => $this->notes,
            'input_lots' => $this->whenLoaded('inputLots', fn () => $this->inputLots->map($lot)->values()),
            'output_lots' => $this->whenLoaded('outputLots', fn () => $this->outputLots->map($lot)->values()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
