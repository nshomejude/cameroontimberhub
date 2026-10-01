<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agent's view of a product it ingested (always `draft` until staff
 * publish it).
 *
 * @mixin Product
 */
class AgentProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'slug' => $this->slug,
            'company_id' => $this->company_id,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'name' => $this->name,
            'product_type' => $this->product_type?->value,
            'species_id' => $this->species_id,
            'status' => $this->status?->value,
            'needs_review' => (bool) $this->needs_review,
            'has_image' => $this->primary_image_path !== null,
            'ingested_at' => $this->ingested_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
