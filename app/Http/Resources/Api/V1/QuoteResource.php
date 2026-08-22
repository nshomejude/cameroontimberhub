<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier's quotation, as the buyer who raised the RFQ may see it.
 *
 * Buyer-visible statuses only ever reach here (Quote::scopeBuyerVisible), so a
 * draft or withdrawn quote is filtered out upstream rather than redacted here.
 * Supplier-side internals (`rfq_company_id`, `revision`, `supersedes_quote_id`,
 * negotiation rounds) are not part of the v1 buyer payload.
 *
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'currency' => $this->currency?->value,
            'incoterm' => $this->incoterm?->value,
            'subtotal_amount' => $this->subtotal_amount,
            'shipping_amount' => $this->shipping_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'lead_time_days' => $this->lead_time_days,
            'validity_days' => $this->validity_days,
            'valid_until' => $this->valid_until?->toDateString(),
            'payment_terms' => $this->payment_terms,
            'notes' => $this->notes,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decline_reason' => $this->decline_reason,
            'is_expired' => $this->isExpired(),
            'is_actionable' => $this->isActionable(),
            'rfq_reference' => $this->whenLoaded('rfq', fn () => $this->rfq->reference_code),
            'supplier' => $this->whenLoaded('company', fn () => new SupplierResource($this->company)),
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
