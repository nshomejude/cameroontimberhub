<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Services\SupplierApiScope;
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
 * Buyer contact fields (`buyer_name`, `buyer_company`, `buyer_email`, plus
 * `notes`/`attachments`) are null and `contact_locked` is true while the
 * caller's company is not verified (Company::canRespondToBuyers()); once
 * verified they are
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
        $canRespond = $this->canRespond($request);
        $locked = ! $canRespond;

        return [
            'reference' => $this->reference_code,
            'type' => $this->type?->value,
            'title' => $this->title,
            'project_name' => $this->project_name,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            // Withheld (null) until the company is verified — notes and
            // attachments may carry contact details too (same as the board).
            'buyer_name' => $locked ? null : $this->buyer_name,
            'buyer_company' => $locked ? null : $this->buyer_company,
            'buyer_email' => $locked ? null : $this->buyer_email,
            'buyer_country_code' => $this->buyer_country_code,
            'destination_country_code' => $this->destination_country_code,
            'incoterm' => $this->incoterm?->value,
            'shipping_port' => $this->shipping_port,
            'target_amount' => $this->target_amount,
            'target_currency' => $this->target_currency,
            'deadline' => $this->deadline?->toDateString(),
            'notes' => $locked ? null : $this->notes,
            'attachments' => $locked ? [] : ($this->attachments ?? []),
            'can_respond' => $canRespond,
            'contact_locked' => $locked,
            'created_at' => $this->created_at?->toIso8601String(),
            'items' => RfqItemResource::collection($this->whenLoaded('items')),
            'routing' => $routing instanceof RfqCompany ? [
                'status' => $routing->status->value,
                'status_label' => $routing->status->label(),
                'routed_at' => $routing->routed_at?->toIso8601String(),
                'viewed_at' => $routing->viewed_at?->toIso8601String(),
                'responded_at' => $routing->responded_at?->toIso8601String(),
                'declined_at' => $routing->declined_at?->toIso8601String(),
            ] : null,
        ];
    }

    /** Whether the caller's company may act on (and see the buyer behind) this RFQ. */
    private function canRespond(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && (bool) app(SupplierApiScope::class)->company($user)?->canRespondToBuyers();
    }
}
