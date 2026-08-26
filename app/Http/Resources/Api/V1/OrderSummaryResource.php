<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The order minted by accepting a quote — a receipt-of-award summary, not the
 * order management surface (that stays on the web in v1).
 *
 * Deliberately narrow: no receipt verification token, no documents, no payment
 * instructions, no internal notes. The receipt token is the entire security of
 * the public verification endpoint and must never travel in a list payload.
 *
 * @mixin Order
 */
class OrderSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency?->value,
            'total_amount' => $this->total_amount,
            'supplier_name' => $this->supplier_name,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
