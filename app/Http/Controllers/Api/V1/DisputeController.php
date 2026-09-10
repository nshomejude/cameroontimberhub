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
 * Evidence-with-file-upload and appeal are NOT exposed here for this pass —
 * multipart evidence upload is left to the web for now (mobile evidence
 * submission is a reasonable native-app feature but adds real scope: file
 * validation/streaming over the API), and appeal is a rare, late-lifecycle
 * action better added as a small follow-up than bundled into this first cut.
 * `reply()` covers the buyer's evidence/response text, matching the web
 * route's `POST .../reply` action exactly (text-only; `submitEvidence()`'s
 * description-only path is intentionally left to a follow-up too, so as not
 * to duplicate two very similar text-reply endpoints in the first pass).
 */
class DisputeController extends Controller
{
    public function __construct(
        private readonly BuyerApiScope $scope,
        private readonly DisputeService $disputes,
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
    ) {}

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

        return response()->json([
            'message' => 'Response sent.',
            'data' => new DisputeResource($model->refresh()->load(['evidence', 'messages.user', 'messages.company', 'raisedByUser', 'raisedByCompany', 'respondentCompany'])),
        ]);
    }
}
