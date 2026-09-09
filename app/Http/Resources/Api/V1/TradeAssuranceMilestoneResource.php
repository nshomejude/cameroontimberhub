<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TradeAssuranceMilestone;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Trade Assurance milestone (blueprint §28), shaped to match what
 * `resources/views/public/account/trade-assurance.blade.php` shows: the
 * buyer can confirm it whenever `can_confirm` is true — same pending/
 * in_progress gate the web view's `@if` uses.
 *
 * @mixin TradeAssuranceMilestone
 */
class TradeAssuranceMilestoneResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'amount_share_percent' => $this->amount_share_percent,
            'expected_completion_date' => $this->expected_completion_date?->toDateString(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'can_confirm' => in_array($this->status->value, ['pending', 'in_progress'], true),
        ];
    }
}
