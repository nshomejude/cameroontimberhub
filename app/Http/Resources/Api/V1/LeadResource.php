<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead in the supplier's pipeline — the fields the exporter panel's
 * LeadsTable / LeadForm show. `notes` are private to the company (the API
 * only ever serves leads of the caller's own companies).
 *
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canRespond = (bool) $this->company?->canRespondToBuyers();
        $locked = ! $canRespond;

        return [
            'id' => $this->id,
            'source' => $this->source,
            'status' => $this->status ? ['value' => $this->status->value, 'label' => $this->status->label()] : null,
            // Buyer contact withheld until the company is verified.
            'buyer_name' => $locked ? null : $this->buyer_name,
            'buyer_email' => $locked ? null : $this->buyer_email,
            'can_respond' => $canRespond,
            'contact_locked' => $locked,
            'buyer_country_code' => $this->buyer_country_code,
            'value_amount' => $this->value_amount !== null ? (string) $this->value_amount : null,
            'value_currency' => $this->value_currency,
            'notes' => $this->notes,
            'rfq' => $this->whenLoaded('rfq', fn () => $this->rfq ? [
                'reference_code' => $this->rfq->reference_code,
                'type' => $this->rfq->type?->value,
                'title' => $this->rfq->title,
            ] : null),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
