<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RfqCompanyStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierRfqResource;
use App\Services\SupplierApiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * A supplier's RFQ inbox over token auth.
 *
 * There is no dedicated Filament "RFQ inbox" resource on the web today — a
 * supplier sees requests routed to them through `QuoteForm::quotableRfqs()`
 * (the RFQ picker inside "Create Quote", scoped to approved + routed +
 * not-already-quoted) and through the `rfq_company` rows the Leads resource
 * surfaces. This controller is the first dedicated read surface for the
 * routing relationship itself — see the class docblock on
 * `App\Services\SupplierApiScope` for the scoping this is built on.
 *
 * Every RFQ here is one ROUTED to the caller's company (`rfq_company.company_id`),
 * not merely visible/approved — an RFQ the platform has not routed to this
 * supplier never appears, list or single, and a reference that belongs to
 * someone else's routing 404s rather than 403s (SupplierApiScope's
 * enumeration-safety convention, mirroring BuyerApiScope).
 */
class SupplierRfqController extends Controller
{
    public function __construct(private readonly SupplierApiScope $scope) {}

    /**
     * Paginated, newest-first, optionally filtered by the CALLER'S OWN
     * routing status (`?status=sent|viewed|responded|declined` —
     * `RfqCompanyStatus`'s real values; there is no "quoted" status, a
     * responded routing is what a submitted quote produces).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(RfqCompanyStatus::class)],
        ]);

        $user = $request->user();
        $companyId = $this->scope->company($user)?->getKey();

        $rfqs = $this->scope->routedRfqs($user)
            ->when($request->filled('status'), fn (Builder $q) => $q->whereHas(
                'routings',
                fn (Builder $r) => $r->where('company_id', $companyId)->where('status', $request->string('status')),
            ))
            ->with([
                'items.species:id,slug,common_name',
                'routings' => fn ($r) => $r->where('company_id', $companyId),
            ])
            ->paginate(15);

        return SupplierRfqResource::collection($rfqs);
    }

    /** One RFQ routed to the caller's company, or 404. */
    public function show(Request $request, string $reference): SupplierRfqResource
    {
        $user = $request->user();
        $companyId = $this->scope->company($user)?->getKey();

        $rfq = $this->scope->routedRfq($user, $reference);
        $rfq->load([
            'items.species:id,slug,common_name',
            'routings' => fn ($r) => $r->where('company_id', $companyId),
        ]);

        return new SupplierRfqResource($rfq);
    }
}
