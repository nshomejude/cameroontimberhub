<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Rfq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An RFQ as it appears in a supplier's inbox — the same allow-listed fields
 * RfqResource already renders for the buyer (the omissions there — spam
 * score, IP, internal routing/provenance columns — apply here too, a
 * supplier is no more entitled to them than the buyer is), plus the one
 * thing a buyer's view has no reason to carry: THIS company's own routing
 * row against the RFQ (status sent/viewed/responded/declined, and when it
 * was routed/viewed/responded/declined).
 *
 * Buyer contact fields (`buyer_name`, `buyer_company`, `buyer_email`) are
 * deliberately included here, unlike a stranger reading the RFQ — the same
 * way the exporter Leads/Orders tables already show a supplier the buyer's
 * name and email (see LeadsTable/OrdersTable) once an RFQ is routed to them.
 *
 * The caller MUST eager-load `routings` scoped to the caller's own company
 * (a single row, since `rfq_company` has a unique `[rfq_id, company_id]`
 * index) before wrapping — `SupplierRfqController` does this via
 * `SupplierApiScope`. Loading it unscoped would leak another company's
 * routing row; this resource always renders only the first loaded row.
 *
 * @mixin Rfq
 */
class SupplierRfqResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $routing = $this->whenLoaded('routings', fn () => $this->routings->first());

        return [
            'reference' => $this->reference_code,
            'title' => $this->title,
            'project_name' => $this->project_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
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
            'attachments' => $this->attachments ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => RfqItemResource::collection($this->whenLoaded('items')),
            'routing' => $routing instanceof \App\Models\RfqCompany ? [
                'status' => $routing->status->value,
                'status_label' => $routing->status->label(),
                'routed_at' => $routing->routed_at?->toIso8601String(),
                'viewed_at' => $routing->viewed_at?->toIso8601String(),
                'responded_at' => $routing->responded_at?->toIso8601String(),
                'declined_at' => $routing->declined_at?->toIso8601String(),
            ] : null,
        ];
    }
}
