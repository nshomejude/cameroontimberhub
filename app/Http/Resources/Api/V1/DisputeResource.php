<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Dispute;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single Dispute (blueprint §64) — the API counterpart of
 * `public.disputes.show`/`index`. `messages`/`evidence` are only attached
 * when the caller eager-loaded them, exactly like OrderResource does for
 * `items`.
 *
 * @mixin Dispute
 */
class DisputeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'description' => $this->description,
            'raised_by_user_name' => $this->whenLoaded('raisedByUser', fn () => $this->raisedByUser?->name),
            'raised_by_company_name' => $this->whenLoaded('raisedByCompany', fn () => $this->raisedByCompany?->name),
            'respondent_company_name' => $this->whenLoaded('respondentCompany', fn () => $this->respondentCompany?->name),
            'resolution_notes' => $this->resolution_notes,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'appealed_at' => $this->appealed_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'evidence' => DisputeEvidenceResource::collection($this->whenLoaded('evidence')),
            'messages' => DisputeMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
