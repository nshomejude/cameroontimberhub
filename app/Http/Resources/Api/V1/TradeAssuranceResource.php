<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TradeAssuranceAgreement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order's Trade Assurance agreement — coordination/tracking only, never
 * escrow or fund custody (see TradeAssuranceAgreement's docblock). Mirrors
 * what `public.account.trade-assurance` shows on the web.
 *
 * @mixin TradeAssuranceAgreement
 */
class TradeAssuranceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_reference' => $this->order?->reference_code,
            'milestones' => TradeAssuranceMilestoneResource::collection($this->whenLoaded('milestones')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
