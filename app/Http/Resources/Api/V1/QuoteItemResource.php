<?php

namespace App\Http\Resources\Api\V1;

use App\Models\QuoteItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QuoteItem */
class QuoteItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'form' => $this->form,
            'grade' => $this->grade,
            'dimensions' => $this->dimensions,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
        ];
    }
}
