<?php

namespace App\Http\Resources\Api\V1;

use App\Models\RfqItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RfqItem */
class RfqItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'species_text' => $this->species_text,
            'form' => $this->form,
            'grade' => $this->grade,
            'dimensions' => $this->dimensions,
            'moisture_content' => $this->moisture_content,
            'quantity' => $this->quantity,
            'unit' => $this->unit,
            'species' => $this->whenLoaded('species', fn () => $this->species === null ? null : [
                'slug' => $this->species->slug,
                'common_name' => $this->species->common_name,
            ]),
        ];
    }
}
