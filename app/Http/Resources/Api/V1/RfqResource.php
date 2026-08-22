<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Rfq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The buyer's own request for quotation.
 *
 * This is an explicit allow-list and the omissions are the point. The RFQ row
 * carries platform-internal moderation state that a buyer must never see:
 *
 *   - `spam_score` / `is_spam` — the anti-spam verdict. Exposing it hands an
 *     abuser a scoring oracle to tune submissions against.
 *   - `ip_address` — collected for abuse handling, not for display.
 *   - `visibility`, `source`, `user_id`, `reorder_of_order_id` — routing and
 *     provenance internals.
 *
 * The RFQ is only ever rendered to the account that owns it, so `buyer_email`
 * here is the token holder's own address and never another buyer's.
 *
 * @mixin Rfq
 */
class RfqResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference_code,
            'title' => $this->title,
            'project_name' => $this->project_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'email_verified' => $this->email_verified_at !== null,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'buyer_name' => $this->buyer_name,
            'buyer_company' => $this->buyer_company,
            'buyer_email' => $this->buyer_email,
            'buyer_country_code' => $this->buyer_country_code,
            'destination_country_code' => $this->destination_country_code,
            'incoterm' => $this->incoterm?->value,
            'shipping_port' => $this->shipping_port,
            'target_amount' => $this->target_amount,
            'target_currency' => $this->target_currency,
            'deadline' => $this->deadline?->toDateString(),
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => RfqItemResource::collection($this->whenLoaded('items')),
            'quotes_count' => $this->whenCounted('quotes'),
        ];
    }
}
