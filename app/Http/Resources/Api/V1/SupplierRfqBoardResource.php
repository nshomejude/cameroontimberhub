<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Rfq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An open buyer request as it appears on a supplier's "Buyer requests" board
 * (GET /api/v1/supplier/rfq-board). Unlike SupplierRfqResource (an RFQ
 * already ROUTED to the caller), the buyer's identity and contact details —
 * name, company, email, notes (free text that may carry contact info) and
 * attachments — are withheld: only the commercial spec needed to decide
 * whether to quote. Quoting (or express-interest) routes the RFQ to the
 * caller, after which the normal routed view applies.
 *
 * @mixin Rfq
 */
class SupplierRfqBoardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'type' => $this->type?->value,
            'title' => $this->title,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'buyer_country_code' => $this->buyer_country_code,
            'destination_country_code' => $this->destination_country_code,
            'incoterm' => $this->incoterm?->value,
            'shipping_port' => $this->shipping_port,
            'target_amount' => $this->target_amount,
            'target_currency' => $this->target_currency,
            'deadline' => $this->deadline?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => RfqItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
