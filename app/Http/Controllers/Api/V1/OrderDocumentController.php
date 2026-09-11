<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderDocumentResource;
use App\Models\OrderDocument;
use App\Services\BuyerApiScope;
use App\Services\OrderDocumentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An order's documents (proof of delivery, invoice, packing list, ...) over
 * token auth — the API counterpart of the documents card
 * `OrderLifecycleService` writes into the messaging thread, and of
 * `Http\Controllers\OrderDocumentDownloadController` for the web.
 *
 * `{orderReference}` is resolved through `BuyerApiScope`, the exact same
 * ownership boundary `OrderController`/`TradeAssuranceController`/
 * `DisputeController` use: another buyer's order 404s here too, never 403s.
 *
 * This is deliberately narrower than `OrderLifecycleService::mayAccessDocument()`,
 * which already allows BOTH the order's buyer AND a member of the supplying
 * company — the same rule `OrderDocumentDownloadController` (web) enforces.
 * This task does not widen `/api/v1/orders/{reference}` access beyond the
 * existing buyer-only `BuyerApiScope::order()` boundary, so a supplier hitting
 * this same route family gets nothing back today (blocked earlier by the
 * `api.buyer` group's `EnsureApiBuyer` gate, which excludes company members
 * entirely). A supplier's own view of THEIR orders (and this same documents
 * endpoint for them) is a separate, larger task — it needs its own orders
 * listing scoped to the supplying company, not a widening of the buyer route
 * family. See this task's final report.
 *
 * `download()` streams directly through this authenticated endpoint via
 * `OrderDocumentService::download()` rather than the web's signed
 * `order-documents.download` route, for the same reason
 * `CompanyDocumentController::download()` gives: that web route sits behind
 * session `auth`, unusable from a Sanctum-token-only mobile client.
 */
class OrderDocumentController extends Controller
{
    public function __construct(
        private readonly BuyerApiScope $scope,
        private readonly OrderDocumentService $documents,
    ) {}

    public function index(Request $request, string $orderReference): AnonymousResourceCollection
    {
        $order = $this->scope->order($request->user(), $orderReference);

        $documents = $order->documents()->latest('id')->get();

        return OrderDocumentResource::collection($documents);
    }

    public function download(Request $request, string $orderReference, OrderDocument $document): StreamedResponse
    {
        $order = $this->scope->order($request->user(), $orderReference);

        abort_unless((int) $document->order_id === (int) $order->getKey(), 404);

        return $this->documents->download($document);
    }
}
