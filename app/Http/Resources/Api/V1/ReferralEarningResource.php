<?php

namespace App\Http\Resources\Api\V1;

use App\Models\ReferralEarning;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReferralEarning */
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
            // Payout progress (ReferralPayoutService): awaiting_approval |
            // awaiting_payout | processing | unclaimed | failed | paid | cancelled.
            'payout_status' => $payoutStatus = $this->payoutStatus(),
            'payout_status_label' => ReferralEarning::payoutStatusLabel($payoutStatus),
            'payout_method' => $this->status->value === 'paid' ? $this->latestPayout?->method : null,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
