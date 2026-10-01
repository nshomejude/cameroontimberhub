<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Trade\Commands\RecordOrderDeliveryCommand;
use App\Domain\Trade\Commands\RecordOrderShipmentCommand;
use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierOrderResource;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderLifecycleService;
use App\Services\OrderService;
use App\Services\SupplierApiScope;
use App\Support\Bus\CommandBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Supplier order fulfilment by order reference — confirm, start production,
 * ship, update shipment details, mark delivered — WITHOUT needing a chat
 * thread id.
 *
 * Why this exists: accepting a quote (`QuoteService::accept()` ->
 * `OrderService::createFromQuote()`) does NOT open a conversation, so an
 * order is not guaranteed one (a quote accepted from the buyer's signed
 * e-mail link, or a guest buyer's order, never has one). The
 * `conversations/{id}/orders/{order}/*` endpoints therefore cannot be the
 * only way to fulfil an order from the app.
 *
 * No business logic lives here. Each action picks one of the two existing
 * write paths:
 *
 *  - the order HAS a conversation (`Order::conversation`, the same thread
 *    `SupplierOrderResource::conversation_id` reports): the exact
 *    `OrderLifecycleService` call `ChatOrderController` makes, so the buyer
 *    gets the same in-thread card and notification as from the chat;
 *  - it has NONE: the exact path the exporter panel's Orders table uses —
 *    `OrderService::confirm()`/`startProduction()`, and the CommandBus
 *    `RecordOrderShipmentCommand`/`RecordOrderDeliveryCommand` for
 *    ship/deliver — plus `OrderLifecycleService::recordShipmentDetails()`/
 *    `recordDeliveryFacts()` for the optional tracking/delivery fields.
 *
 * Scoping is `SupplierApiScope::order()` (another company's reference 404s).
 * A disallowed transition is a 409 `order_transition_not_allowed` (or
 * `order_action_not_allowed` for tracking), the same codes as the chat
 * endpoints. Every action answers with the refreshed `SupplierOrderResource`.
 */
class SupplierOrderFulfilmentController extends Controller
{
    public function __construct(
        private readonly SupplierApiScope $scope,
        private readonly OrderLifecycleService $lifecycle,
        private readonly OrderService $orders,
        private readonly CommandBus $bus,
    ) {}

    public function confirm(Request $request, string $reference): SupplierOrderResource
    {
        return $this->run(
            $request,
            $reference,
            fn (Conversation $c, Order $o, User $u) => $this->lifecycle->confirm($c, $o, $u),
            fn (Order $o, User $u) => $this->orders->confirm($o, $u),
        );
    }

    public function startProduction(Request $request, string $reference): SupplierOrderResource
    {
        return $this->run(
            $request,
            $reference,
            fn (Conversation $c, Order $o, User $u) => $this->lifecycle->startProduction($c, $o, $u),
            fn (Order $o, User $u) => $this->orders->startProduction($o, $u),
        );
    }

    public function ship(Request $request, string $reference): SupplierOrderResource
    {
        $tracking = ChatOrderController::trackingRules($request);

        return $this->run(
            $request,
            $reference,
            fn (Conversation $c, Order $o, User $u) => $this->lifecycle->ship($c, $o, $u, $tracking),
            function (Order $o, User $u) use ($tracking) {
                DB::transaction(function () use ($o, $u, $tracking) {
                    if ($tracking !== []) {
                        $this->lifecycle->recordShipmentDetails($o, $u, $tracking);
                    }

                    $this->bus->dispatch(new RecordOrderShipmentCommand(orderId: $o->getKey(), actingUserId: $u->getKey()));
                });
            },
        );
    }

    public function updateTracking(Request $request, string $reference): SupplierOrderResource
    {
        $tracking = ChatOrderController::trackingRules($request);

        return $this->run(
            $request,
            $reference,
            fn (Conversation $c, Order $o, User $u) => $this->lifecycle->updateTracking($c, $o, $u, $tracking),
            fn (Order $o, User $u) => $this->lifecycle->recordShipmentDetails($o, $u, $tracking),
            'order_action_not_allowed',
        );
    }

    public function deliver(Request $request, string $reference): SupplierOrderResource
    {
        $data = $request->validate([
            'received_by' => ['nullable', 'string', 'max:160'],
            'location' => ['nullable', 'string', 'max:200'],
            'proof' => ['nullable', 'array', 'max:5'],
            'proof.*' => ChatOrderController::fileRules(),
        ]);

        $receivedBy = $data['received_by'] ?? null;
        $location = $data['location'] ?? null;
        $proof = $request->file('proof') ?? [];

        return $this->run(
            $request,
            $reference,
            fn (Conversation $c, Order $o, User $u) => $this->lifecycle->deliver($c, $o, $u, $receivedBy, $location, $proof),
            function (Order $o, User $u) use ($receivedBy, $location, $proof) {
                // Refuse BEFORE storing any proof file for an order that
                // cannot be delivered from its current status.
                if (! in_array('delivered', OrderService::TRANSITIONS[$o->status->value] ?? [], true)) {
                    throw new RuntimeException("Illegal order transition {$o->status->value} -> delivered");
                }

                DB::transaction(function () use ($o, $u, $receivedBy, $location, $proof) {
                    $this->lifecycle->recordDeliveryFacts($o, $u, $receivedBy, $location, $proof);

                    $this->bus->dispatch(new RecordOrderDeliveryCommand(orderId: $o->getKey(), actingUserId: $u->getKey()));
                });
            },
        );
    }

    /**
     * Resolve the caller's order, run the threaded or threadless write, and
     * answer with the refreshed order.
     *
     * @param  callable(Conversation, Order, User): mixed  $threaded
     * @param  callable(Order, User): mixed  $threadless
     */
    private function run(
        Request $request,
        string $reference,
        callable $threaded,
        callable $threadless,
        string $code = 'order_transition_not_allowed',
    ): SupplierOrderResource {
        $user = $request->user();
        $order = $this->scope->order($user, $reference);
        $order->loadMissing('conversation');

        try {
            $order->conversation !== null
                ? $threaded($order->conversation, $order, $user)
                : $threadless($order, $user);
        } catch (HttpExceptionInterface|ApiException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), $code, $e);
        }

        $order = $order->fresh(['items', 'conversation']);

        return new SupplierOrderResource($order);
    }
}
