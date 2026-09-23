<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A fleet vehicle, API counterpart of `Vehicles\Tables\VehiclesTable` /
 * `Schemas\VehicleForm` — same real columns, no invented fields.
 *
 * @mixin Vehicle
 */
class VehicleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'registration_number' => $this->registration_number,
            'type' => $this->type,
            'capacity_tonnes' => $this->capacity_tonnes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
