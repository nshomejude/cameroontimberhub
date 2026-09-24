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
            // Added so a supplier's quote-submission payload
            // (StoreSupplierQuoteRequest::items.*.species_id) can reference
            // the real numeric id — previously only slug/common_name were
            // exposed here, which StoreSupplierQuoteRequest cannot validate
            // against (`exists:species,id`).
            'species_id' => $this->species_id,
            'species' => $this->whenLoaded('species', fn () => $this->species === null ? null : [
                'id' => $this->species->id,
                'slug' => $this->species->slug,
                'common_name' => $this->species->common_name,
            ]),
        ];
    }
}
