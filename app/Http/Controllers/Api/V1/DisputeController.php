<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compliance\Commands\OpenDisputeCommand;
use App\Domain\Compliance\Queries\ListOrderDisputesQuery;
use App\Enums\DisputeCategory;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OpenDisputeRequest;
use App\Http\Requests\Api\V1\ReplyDisputeRequest;
use App\Http\Resources\Api\V1\DisputeResource;
use App\Models\Dispute;
use App\Services\BuyerApiScope;
use App\Services\DisputeNotifier;
use App\Services\DisputeService;
use App\Support\Bus\CommandBus;
use App\Support\Bus\QueryBus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

/**
 * The Dispute Resolution workflow (blueprint §64) over token auth — the API
 * counterpart of `Http\Controllers\Public\DisputeController` for the buyer
 * mobile app.
 *
 * `{orderReference}` is resolved through BuyerApiScope, the same ownership
 * boundary RfqController/QuoteController/OrderController/TradeAssuranceController
 * use: another buyer's order 404s, never 403s, so a reference-grinder learns
 * nothing about which order references are real. Once the order itself is
 * confirmed to belong to this buyer, `isParty()`/`isOrderParty()` (unused
 * here beyond that — a buyer resolved via BuyerApiScope is always the order's
 * `user_id`) still guards each dispute action exactly as the web controller
 * does, so this stays in lock-step with `Public\DisputeController`.
 *
 * `index()` goes through ListOrderDisputesQuery via QueryBus, mirroring the
 * ListBuyerOrdersQuery convention. `store()` goes through Task 2.3's
 * `App\Domain\Compliance\Commands\OpenDisputeCommand` via CommandBus — it had
 * landed by the time this controller was wired up, so this does not invent a
 * second way to open a dispute. `reply()` has no Command yet, so it still
 * calls `DisputeService::reply()` directly, exactly like the web controller.
 *
 * `evidence()` (multipart, optional `file`) and `appeal()` mirror the web
 * `submitEvidence()`/`appeal()` actions exactly — same validation, same
 * DisputeService::submitEvidence() / Dispute::appeal() write paths.
 * `buyerIndex()` lists the buyer's disputes across all of their orders.
 */
class DisputeController extends Controller
{
    public function __construct(
        private readonly BuyerApiScope $scope,
        private readonly DisputeService $disputes,
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
    ) {}

    /**
     * `GET /disputes` — every dispute on any of the buyer's own orders,
     * newest first, paginated. Scoped by `orders.user_id` (the same buyer
     * boundary BuyerApiScope::orders() uses), never by a request parameter.
     * `order_reference` is added so the client can deep-link to the per-order
     * dispute endpoints.
     */
    public function buyerIndex(Request $request): AnonymousResourceCollection
    {
        $disputes = Dispute::query()
            ->forBuyer($request->user())
            ->paginate(20)
            ->withQueryString();

        return DisputeResource::collection($disputes);
    }

    /**
     * Multipart evidence upload — the API counterpart of the web
     * `submitEvidence()`: same validation, same DisputeService::submitEvidence()
     * write path (which re-checks MIME/extension/size and party membership).
     */
    public function evidence(Request $request, string $orderReference, int $dispute): JsonResponse
    {
        $model = $this->resolve($request, $orderReference, $dispute);

        $data = $request->validate([
            'description' => ['required', 'string', 'max:2000'],
            'file' => ['nullable', 'file', 'max:'.(DisputeService::MAX_BYTES / 1024)],
        ]);

        try {
            $this->disputes->submitEvidence($model, $request->user(), $data['description'], $request->file('file'));
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'dispute_not_actionable', $e);
        }

        return response()->json([
            'message' => 'Evidence submitted.',
            'data' => new DisputeResource($model->refresh()->load(['evidence', 'messages.user', 'messages.company', 'raisedByUser', 'raisedByCompany', 'respondentCompany'])),
        ], 201);
    }

    /** Appeal a resolved dispute — same Dispute::appeal() the web controller calls. */
    public function appeal(Request $request, string $orderReference, int $dispute): JsonResponse
    {
        $model = $this->resolve($request, $orderReference, $dispute);

        try {
            $model->appeal($request->user());
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'dispute_not_actionable', $e);
        }

        return response()->json([
            'message' => 'Dispute appealed.',
            'data' => new DisputeResource($model->refresh()->load(['raisedByUser', 'raisedByCompany', 'respondentCompany'])),
        ]);
    }

    private function resolve(Request $request, string $orderReference, int $dispute): Dispute
    {
        $order = $this->scope->order($request->user(), $orderReference);

        /** @var Dispute $model */
        $model = Dispute::query()->where('order_id', $order->getKey())->findOrFail($dispute);

        abort_unless($model->isParty($request->user()), 403);

        return $model;
    }

    public function index(Request $request, string $orderReference): AnonymousResourceCollection
    {
        $order = $this->scope->order($request->user(), $orderReference);

        $disputes = $this->queryBus->dispatch(new ListOrderDisputesQuery(
            orderId: $order->getKey(),
            userId: $request->user()->getKey(),
        ));

        return DisputeResource::collection($disputes);
    }

    public function show(Request $request, string $orderReference, int $dispute): JsonResponse
    {
        $order = $this->scope->order($request->user(), $orderReference);

        /** @var Dispute $model */
        $model = Dispute::query()->where('order_id', $order->getKey())->findOrFail($dispute);

        abort_unless($model->isParty($request->user()), 403);

        $model->load(['evidence.submittedByUser', 'evidence.submittedByCompany', 'messages.user', 'messages.company', 'raisedByUser', 'raisedByCompany', 'respondentCompany']);

        return response()->json(['data' => new DisputeResource($model)]);
    }

    public function store(OpenDisputeRequest $request, string $orderReference): JsonResponse
    {
        $order = $this->scope->order($request->user(), $orderReference);

        try {
            $dispute = $this->commandBus->dispatch(new OpenDisputeCommand(
                orderId: $order->getKey(),
                actingUserId: $request->user()->getKey(),
                category: DisputeCategory::from($request->validated('category')),
                description: $request->validated('description'),
            ));
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'dispute_not_actionable', $e);
        }

        // Notify the OTHER party, mirroring how reply() notifies below —
        // wired here rather than inside OpenDisputeCommand's handler for the
        // same additive reasoning documented on reply()'s notify call.
        // DisputeNotifier re-derives parties from the order (the respondent
        // is usually a COMPANY, so respondentUser alone missed its members)
        // and also alerts staff holding disputes.manage.
        app(DisputeNotifier::class)->opened($dispute, $request->user());

        return response()->json([
            'message' => 'Dispute opened.',
            'data' => new DisputeResource($dispute),
        ], 201);
    }

    public function reply(ReplyDisputeRequest $request, string $orderReference, int $dispute): JsonResponse
    {
        $order = $this->scope->order($request->user(), $orderReference);

        /** @var Dispute $model */
        $model = Dispute::query()->where('order_id', $order->getKey())->findOrFail($dispute);

        abort_unless($model->isParty($request->user()), 403);

        try {
            $this->disputes->reply($model, $request->user(), $request->validated('body'));
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'dispute_not_actionable', $e);
        }

        // Notify the OTHER party. Wired here rather than inside
        // DisputeService::reply() itself: that method had an uncommitted,
        // in-flight change from another agent (a can_reply/isReplyable guard)
        // when this was built, so the notification is added additively at
        // the controller layer instead of touching that method's body.
        app(DisputeNotifier::class)->replied($model, $request->user(), $request->validated('body'));

        return response()->json([
            'message' => 'Response sent.',
            'data' => new DisputeResource($model->refresh()->load(['evidence', 'messages.user', 'messages.company', 'raisedByUser', 'raisedByCompany', 'respondentCompany'])),
        ]);
    }
}
