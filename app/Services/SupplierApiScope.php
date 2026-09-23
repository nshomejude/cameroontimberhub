<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Order;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\RfqCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The API's supplier-scoping boundary — the token-auth counterpart to
 * BuyerApiScope, but scoped by the caller's COMPANY rather than by user id.
 *
 * "The caller's company" is resolved the same way CompanyDocumentController
 * already does: the first `company_user` membership. `EnsureApiSupplier`
 * already guarantees at least one exists before any controller using this
 * class runs. A user belonging to more than one company only ever sees the
 * first's data here — multi-company supplier accounts are out of scope, same
 * caveat as CompanyDocumentController's.
 *
 * Three things a supplier can reach, none of them by user_id:
 *
 *  - RFQs ROUTED to their company (`rfq_company` rows), not RFQs they own.
 *  - Quotes their company itself submitted.
 *  - Orders where their company is the SUPPLIER side (`orders.company_id`) —
 *    the exact scoping OrderResource::getEloquentQuery() uses for the
 *    exporter panel (`company->dashboardOwned($user)`), just expressed
 *    through the resolved company instead of a fresh membership check.
 *
 * Every lookup resolves with `firstOrFail()` over an already-scoped query, so
 * a record that exists but is not this company's is indistinguishable from
 * one that does not exist at all: both 404, never 403 — the same
 * enumeration-safety convention as BuyerApiScope.
 */
class SupplierApiScope
{
    /** The caller's own company, or null for a user with no membership (should not reach here behind api.supplier). */
    public function company(User $supplier): ?Company
    {
        return $supplier->companies()->first();
    }

    /** RFQs routed to this supplier's company, newest routing first. */
    public function routedRfqs(User $supplier): Builder
    {
        $companyId = $this->company($supplier)?->getKey();

        return Rfq::query()
            ->whereHas('routings', fn (Builder $r) => $r->where('company_id', $companyId))
            ->orderByDesc('created_at');
    }

    /** One RFQ routed to this supplier's company by reference code, or 404. */
    public function routedRfq(User $supplier, string $reference): Rfq
    {
        return $this->routedRfqs($supplier)
            ->where('reference_code', $reference)
            ->firstOrFail();
    }

    /** The routing row (rfq_company) pairing this supplier's company with the given RFQ, or 404. */
    public function routing(User $supplier, Rfq $rfq): RfqCompany
    {
        $companyId = $this->company($supplier)?->getKey();

        return RfqCompany::query()
            ->where('rfq_id', $rfq->getKey())
            ->where('company_id', $companyId)
            ->firstOrFail();
    }

    /** Quotes this supplier's own company has submitted, newest first. */
    public function quotes(User $supplier): Builder
    {
        $companyId = $this->company($supplier)?->getKey();

        return Quote::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at');
    }

    /** Orders where this supplier's company is the supplier side, newest first. */
    public function orders(User $supplier): Builder
    {
        $companyId = $this->company($supplier)?->getKey();

        return Order::query()
            ->where('company_id', $companyId)
            ->orderByDesc('created_at');
    }

    /** One of the supplier's own sales orders by reference code, or 404. */
    public function order(User $supplier, string $reference): Order
    {
        return $this->orders($supplier)
            ->where('reference_code', $reference)
            ->firstOrFail();
    }
}
