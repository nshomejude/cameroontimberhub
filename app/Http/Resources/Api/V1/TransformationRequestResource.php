<?php

namespace App\Http\Resources\Api\V1;

use App\Services\TransformationRequestService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The mobile-agreed transformation-request payload shape. `actions[]` is
 * computed per-CALLER by TransformationRequestService::actionsFor() — the
 * resource never guesses at auth, it asks the request for `$request->user()`.
 */
class TransformationRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'reference' => $this->reference_code,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'service' => $this->service,
            'species' => $this->species ? [
                'slug' => $this->species->slug,
                'common_name' => $this->species->common_name,
            ] : null,
            'volume_m3' => (string) $this->volume_m3,
            'deadline' => $this->deadline?->toDateString(),
            'requester' => [
                'name' => $this->requesterCompany?->name,
                'slug' => $this->requesterCompany?->slug,
            ],
            'provider' => [
                'name' => $this->providerCompany?->name,
                'slug' => $this->providerCompany?->slug,
            ],
            'quote' => $this->quote_amount !== null ? [
                'amount' => (string) $this->quote_amount,
                'currency' => $this->quote_currency,
                'lead_time_days' => $this->quote_lead_time_days,
                'notes' => $this->quote_notes,
            ] : null,
            'timeline' => $this->timeline ?? [],
            'lot_transformation_id' => $this->lot_transformation_id,
            'actions' => $user ? app(TransformationRequestService::class)->actionsFor($user, $this->resource) : [],
        ];
    }
}
