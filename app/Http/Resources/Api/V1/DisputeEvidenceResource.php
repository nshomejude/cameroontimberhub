<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DisputeEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single piece of evidence submitted on a Dispute. Deliberately never
 * exposes `storage_path` or any downloadable URL — the file lives on the
 * private `documents` disk and, like OrderDocument, access is re-checked by
 * a dedicated download route, never trusted from this row (see
 * DisputeEvidence's own docblock). This resource is metadata-only.
 *
 * @mixin DisputeEvidence
 */
class DisputeEvidenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'description' => $this->description,
            'has_file' => $this->hasFile(),
            'original_filename' => $this->when($this->hasFile(), $this->original_filename),
            'submitted_by_user_name' => $this->whenLoaded('submittedByUser', fn () => $this->submittedByUser?->name),
            'submitted_by_company_name' => $this->whenLoaded('submittedByCompany', fn () => $this->submittedByCompany?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
