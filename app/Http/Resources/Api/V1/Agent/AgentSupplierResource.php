<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An agent's view of a supplier it ingested: identity, provenance and the
 * moderation state (status, needs_review, publicly_visible) it should poll.
 *
 * @mixin Company
 */
class AgentSupplierResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'source' => $this->source,
            'external_id' => $this->external_id,
            'legal_name' => $this->legal_name,
            'trade_name' => $this->trade_name,
            'city' => $this->city,
            'region' => $this->region,
            'country_code' => $this->country_code,
            'status' => $this->status?->value,
            'needs_review' => (bool) $this->needs_review,
            'publicly_visible' => Company::query()->publiclyVisible()->whereKey($this->id)->exists(),
            'has_logo' => $this->logo_path !== null,
            'claimed' => $this->users()->exists(),
            'products_count' => $this->products()->count(),
            'source_url' => $this->source_url,
            'ingested_at' => $this->ingested_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
