<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Logistics\Queries\GetOrderShipmentTrackingQuery;
use App\Domain\Trade\Queries\ListBuyerOrdersQuery;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\ShipmentTrackingResource;
use App\Services\BuyerApiScope;
use App\Support\Bus\QueryBus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Buyer orders over token auth — the API counterpart of `/account/orders`.
 *
 * `index()` goes through the same ListBuyerOrdersQuery (dispatched via
 * QueryBus) that backs AccountController::orders(), so pagination, ordering
 * and eager loads are identical to the web account page — no second,
 * parallel order-listing read path.
 *
 * `show()` is scoped through BuyerApiScope, exactly like RfqController and
 * QuoteController: another buyer's reference 404s, never 403s, so a
 * reference-grinder learns nothing about which references are real.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly BuyerApiScope $scope,
        private readonly QueryBus $queryBus,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = $this->queryBus->dispatch(new ListBuyerOrdersQuery(
            userId: $request->user()->getKey(),
            perPage: 15,
        ));

        return OrderResource::collection($orders);
    }

    public function show(Request $request, string $reference): OrderResource
    {
        $order = $this->scope->order($request->user(), $reference);
        $order->load(['items', 'tradeAssuranceAgreement']);

        return new OrderResource($order);
    }

    /**
     * The order's shipment(s) plus each one's checkpoint history — a natural
     * companion to `show()`/`trade-assurance` (blueprint §45-46 wiring
     * exposed to the buyer app). Goes through GetOrderShipmentTrackingQuery
     * via QueryBus, which itself enforces ownership through the same
     * BuyerApiScope::order() boundary `show()` uses: another buyer's
     * reference 404s here too. An order with no linked shipment yet returns
     * an empty `data` array, not an error.
     */
    public function shipmentTracking(Request $request, string $reference): AnonymousResourceCollection
    {
        $shipments = $this->queryBus->dispatch(new GetOrderShipmentTrackingQuery(
            userId: $request->user()->getKey(),
            reference: $reference,
        ));

        return ShipmentTrackingResource::collection($shipments);
    }
}
