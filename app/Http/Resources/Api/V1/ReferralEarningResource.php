<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ReferralEarning */
class ReferralEarningResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source_reference' => $this->source_reference,
            'amount_label' => $this->amountLabel(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'at' => ($this->paid_at ?? $this->approved_at ?? $this->created_at)?->toIso8601String(),
        ];
    }
}
