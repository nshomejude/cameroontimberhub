<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RfqType;
use App\Exceptions\Api\CompanyVerificationRequiredException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierRfqBoardResource;
use App\Http\Resources\Api\V1\SupplierRfqResource;
use App\Models\Company;
use App\Models\Rfq;
use App\Services\RfqMatchingService;
use App\Services\RfqOpenRequestService;
use App\Services\SupplierApiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * The supplier "Open buyer requests" board over token auth: approved, open
 * RFQs that match the caller's company (RfqMatchingService::openRequestsFor(),
 * the inverse of the candidate query used for routing) but are not routed to
 * it yet. Buyer contact details are hidden (SupplierRfqBoardResource).
 *
 * express-interest routes the RFQ to the caller's company through
 * RfqOpenRequestService::selfRoute() (same routeDetailed() path as staff
 * routing, leads_receive respected) — after which it is in the supplier's
 * RFQ inbox and quotable via POST supplier/rfqs/{reference}/quote. That quote
 * endpoint also self-routes on its own, so express-interest is optional.
 */
class SupplierRfqBoardController extends Controller
{
    public function __construct(
        private readonly SupplierApiScope $scope,
        private readonly RfqMatchingService $matching,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['nullable', Rule::enum(RfqType::class)],
            'species' => ['nullable', 'string', 'max:120'],
        ]);

        /** @var Company $company */
        $company = $this->scope->company($request->user());

        $rfqs = $this->matching->openRequestsFor($company)
            ->when($request->filled('type'), fn (Builder $q) => $q->ofType(RfqType::from((string) $request->string('type'))))
            ->when($request->filled('species'), fn (Builder $q) => $q->whereHas(
                'items.species',
                fn (Builder $s) => $s->where('slug', (string) $request->string('species')),
            ))
            ->with('items.species:id,slug,common_name')
            ->paginate(15);

        return SupplierRfqBoardResource::collection($rfqs);
    }

    public function expressInterest(Request $request, string $reference, RfqOpenRequestService $open): JsonResponse
    {
        $user = $request->user();
        /** @var Company $company */
        $company = $this->scope->company($user);

        CompanyVerificationRequiredException::unless($company);

        $rfq = Rfq::where('reference_code', $reference)->first();

        if ($rfq === null || ! $open->selfRoute($rfq, $company, $user)) {
            abort(404);
        }

        $rfq->load([
            'items.species:id,slug,common_name',
            'routings' => fn ($r) => $r->where('company_id', $company->getKey()),
        ]);

        return response()->json(['data' => new SupplierRfqResource($rfq)]);
    }
}
