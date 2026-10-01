<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\OrderDocumentResource;
use App\Http\Resources\Api\V1\ShipmentTrackingResource;
use App\Http\Resources\Api\V1\SupplierOrderResource;
use App\Models\OrderDocument;
use App\Models\Shipment;
use App\Services\OrderDocumentService;
use App\Services\SupplierApiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A supplier's own sales orders over token auth — orders where the caller's
 * company is the SUPPLIER side (`orders.company_id`), the exact scoping
 * `Filament\Exporter\Resources\Orders\OrderResource::getEloquentQuery()`
 * uses for the exporter panel (`company->dashboardOwned($user)`), reached
 * here through `SupplierApiScope::orders()`/`order()` instead.
 *
 * Renders through `SupplierOrderResource`, not the buyer-facing
 * `OrderResource` — see that class's docblock for why (buyer identity in,
 * `supplier_name` out).
 */
class SupplierOrderController extends Controller
{
    public function __construct(
        private readonly SupplierApiScope $scope,
        private readonly OrderDocumentService $documents,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
        ]);

        $orders = $this->scope->orders($request->user())
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->with('conversation')
            ->paginate(15);

        return SupplierOrderResource::collection($orders);
    }

    /** One of the caller's own sales orders by reference code, or 404. */
    public function show(Request $request, string $reference): SupplierOrderResource
    {
        $order = $this->scope->order($request->user(), $reference);
        $order->load(['items', 'conversation']);

        return new SupplierOrderResource($order);
    }

    /**
     * The order's documents — the supplier-side counterpart of
     * `OrderDocumentController::index()` (buyer), same resource, scoped
     * through `SupplierApiScope::order()` instead of `BuyerApiScope`. Being
     * a member of the supplying company is the other half of
     * `OrderLifecycleService::mayAccessDocument()`'s rule, so this is not a
     * widening of who may see a document.
     */
    public function documents(Request $request, string $reference): AnonymousResourceCollection
    {
        $order = $this->scope->order($request->user(), $reference);

        return OrderDocumentResource::collection($order->documents()->latest('id')->get());
    }

    /** Stream one of the order's documents; another order's document 404s. */
    public function downloadDocument(Request $request, string $reference, OrderDocument $document): StreamedResponse
    {
        $order = $this->scope->order($request->user(), $reference);

        abort_unless((int) $document->order_id === (int) $order->getKey(), 404);

        return $this->documents->download($document);
    }

    /**
     * The order's shipment(s) with checkpoint history, oldest checkpoint
     * first — same query and ordering as `GetOrderShipmentTrackingHandler`
     * (buyer `orders/{reference}/shipments`), same resource, scoped to the
     * supplying company instead of the buyer.
     */
    public function shipments(Request $request, string $reference): AnonymousResourceCollection
    {
        $order = $this->scope->order($request->user(), $reference);

        $shipments = Shipment::query()
            ->where('order_id', $order->getKey())
            ->with(['checkpointUpdates' => fn ($relation) => $relation->orderBy('occurred_at')->orderBy('id')])
            ->orderBy('created_at')
            ->get();

        return ShipmentTrackingResource::collection($shipments);
    }
}
