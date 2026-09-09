<?php

namespace App\Http\Resources\Api\V1;

use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One immutable order line — a snapshot copy of the accepted quote's line
 * taken at award time (see OrderItem docblock). `species_name`/`form`/`unit`
 * are read as the plain strings frozen on the row, never re-derived from a
 * relation that could have changed since.
 *
 * @mixin OrderItem
 */
class OrderItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'species_name' => $this->species_name,
            'form' => $this->formLabel(),
            'grade' => $this->grade,
            'dimensions' => $this->dimensions,
            'quantity' => $this->quantity,
            'unit' => $this->unitLabel(),
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
        ];
    }
}
