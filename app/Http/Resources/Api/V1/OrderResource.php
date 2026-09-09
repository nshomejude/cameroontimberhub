<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single order's detail — the API counterpart to `/account/orders/{order}`
 * on the web (there is no dedicated web detail page yet; this is the same
 * data the account orders list already renders per-row, plus line items).
 *
 * Deliberately still narrow like OrderSummaryResource: no receipt
 * verification token, no internal payment notes, no cancellation reason
 * beyond what the buyer is meant to see. Items are only attached when the
 * caller eager-loaded them, exactly like QuoteResource does for its items.
 *
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'payment_status' => $this->payment_status?->value,
            'currency' => $this->currency?->value,
            'subtotal_amount' => $this->subtotal_amount,
            'shipping_amount' => $this->shipping_amount,
            'tax_amount' => $this->tax_amount,
            'total_amount' => $this->total_amount,
            'incoterm' => $this->incoterm?->value,
            'payment_terms' => $this->payment_terms,
            'lead_time_days' => $this->lead_time_days,
            'shipping_port' => $this->shipping_port,
            'destination_country_code' => $this->destination_country_code,
            'expected_delivery_at' => $this->expected_delivery_at?->toDateString(),
            'etd' => $this->etd?->toDateString(),
            'eta' => $this->eta?->toDateString(),
            'supplier_name' => $this->supplier_name,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'production_started_at' => $this->production_started_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'has_trade_assurance' => $this->resource->relationLoaded('tradeAssuranceAgreement')
                ? $this->tradeAssuranceAgreement !== null
                : null,
        ];
    }
}
