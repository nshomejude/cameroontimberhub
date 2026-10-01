<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Capacity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A declared capacity of the caller's company — the exporter CapacityForm
 * fields (capability, quantity, unit, period).
 *
 * @mixin Capacity
 */
class CapacityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'capability' => $this->capability,
            'quantity' => (string) $this->quantity,
            'unit' => $this->unit,
            'period' => $this->period,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
