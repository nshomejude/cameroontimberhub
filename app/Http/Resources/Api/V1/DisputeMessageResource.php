<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DisputeMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single threaded reply on a Dispute.
 *
 * @mixin DisputeMessage
 */
class DisputeMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'user_name' => $this->whenLoaded('user', fn () => $this->user?->name),
            'company_name' => $this->whenLoaded('company', fn () => $this->company?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
