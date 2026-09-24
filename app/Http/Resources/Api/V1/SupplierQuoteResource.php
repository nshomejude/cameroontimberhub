<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A supplier's own quotation, as their own company may see it.
 *
 * `QuoteResource`'s docblock explicitly says supplier-side internals
 * (`rfq_company_id`, `revision`, `supersedes_quote_id`, negotiation rounds)
 * are not part of the buyer payload — those are internals to the BUYER, not
 * to the supplier who submitted the quote. This resource is that richer
 * view: everything `QuoteResource` renders, plus the fields a supplier is
 * entitled to see about their own submission (their own routing row's key,
 * the revision number, and which quote — if any — this one superseded).
 *
 * Also renders draft/withdrawn quotes, unlike `QuoteResource`
 * (`Quote::scopeBuyerVisible()`) — a supplier needs to see their own
 * withdrawn/draft quotes, only the buyer's view filters those out.
 *
 * @mixin Quote
 */
class SupplierQuoteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'revision' => $this->revision,
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
            'viewed_at' => $this->viewed_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decline_reason' => $this->decline_reason,
            'is_expired' => $this->isExpired(),
            'is_actionable' => $this->isActionable(),
            'rfq_company_id' => $this->rfq_company_id,
            'supersedes_quote_reference' => $this->whenLoaded(
                'supersedes',
                fn () => $this->supersedes?->reference_code,
            ),
            'rfq_reference' => $this->whenLoaded('rfq', fn () => $this->rfq->reference_code),
            'items' => QuoteItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
