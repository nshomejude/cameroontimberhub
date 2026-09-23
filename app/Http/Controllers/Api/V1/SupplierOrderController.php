<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierOrderResource;
use App\Services\SupplierApiScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

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
    public function __construct(private readonly SupplierApiScope $scope) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
        ]);

        $orders = $this->scope->orders($request->user())
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->string('status')))
            ->paginate(15);

        return SupplierOrderResource::collection($orders);
    }

    /** One of the caller's own sales orders by reference code, or 404. */
    public function show(Request $request, string $reference): SupplierOrderResource
    {
        $order = $this->scope->order($request->user(), $reference);
        $order->load('items');

        return new SupplierOrderResource($order);
    }
}
