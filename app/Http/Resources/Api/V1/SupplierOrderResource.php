<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its SUPPLIER may see it — the API counterpart of the exporter
 * panel's Orders resource (`OrdersTable` shows `buyer_company`/`buyer_name`
 * per row today), reshaped for the mobile app.
 *
 * Deliberately NOT the same shape as the buyer-facing OrderResource:
 *
 *  - `OrderResource` carries `supplier_name` (the buyer is told who is
 *    fulfilling their order) but never carries buyer identity at all — a
 *    buyer has no reason to see their own name and email echoed back. A
 *    supplier, by contrast, needs the buyer's identity to fulfil and ship
 *    the order — exactly the fields the exporter Orders/Leads tables
 *    already show them (`buyer_name`, `buyer_company`, `buyer_email`,
 *    `buyer_country_code`), so this resource adds them in place of
 *    `supplier_name` (redundant here — it is the caller's own company).
 *  - Every money/status/logistics field OrderResource exposes is safe for a
 *    supplier too — it is THEIR OWN order, they set most of these fields via
 *    the quote they submitted — so this resource otherwise mirrors it
 *    field-for-field rather than inventing a second shape to maintain.
 *
 * @mixin Order
 */
class SupplierOrderResource extends JsonResource
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
            'buyer_name' => $this->buyer_name,
            'buyer_company' => $this->buyer_company,
            'buyer_email' => $this->buyer_email,
            'buyer_country_code' => $this->buyer_country_code,
            'awarded_at' => $this->awarded_at?->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(),
            'production_started_at' => $this->production_started_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
