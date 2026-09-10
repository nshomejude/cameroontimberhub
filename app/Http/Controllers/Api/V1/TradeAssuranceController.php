<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TradeAssuranceResource;
use App\Models\TradeAssuranceMilestone;
use App\Services\BuyerApiScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

/**
 * Buyer Trade Assurance over token auth — the API counterpart of
 * `Http\Controllers\Public\TradeAssuranceController` (blueprint §28).
 *
 * Coordination/tracking only — NOT escrow or fund custody, exactly like the
 * web version. `{orderReference}` is resolved through BuyerApiScope, the
 * same ownership boundary RfqController/QuoteController/OrderController use:
 * another buyer's order 404s, never 403s. `confirmMilestone()` calls into
 * `TradeAssuranceMilestone::confirmByBuyer()` directly — there is no
 * dedicated Command for this yet, and this task is API exposure only, not
 * new domain logic.
 */
class TradeAssuranceController extends Controller
{
    public function __construct(private readonly BuyerApiScope $scope) {}

    public function show(Request $request, string $orderReference): JsonResponse
    {
        $order = $this->scope->order($request->user(), $orderReference);

        $agreement = $order->tradeAssuranceAgreement()->with('milestones')->first();

        if (! $agreement) {
            return response()->json([
                'data' => null,
                'message' => 'A Trade Assurance agreement has not been set up for this order.',
            ]);
        }

        return response()->json([
            'data' => new TradeAssuranceResource($agreement),
        ]);
    }

    public function confirmMilestone(Request $request, string $orderReference, int $milestoneId): JsonResponse
    {
        $buyer = $request->user();
        $order = $this->scope->order($buyer, $orderReference);

        $agreement = $order->tradeAssuranceAgreement()->with('milestones')->first();

        if (! $agreement) {
            throw new ApiException(
                Response::HTTP_NOT_FOUND,
                'not_found',
                'A Trade Assurance agreement has not been set up for this order.',
            );
        }

        /** @var TradeAssuranceMilestone|null $milestone */
        $milestone = $agreement->milestones->firstWhere('id', $milestoneId);

        if (! $milestone) {
            abort(404);
        }

        try {
            $milestone->confirmByBuyer($buyer);
        } catch (RuntimeException $e) {
            throw new ConflictException($e->getMessage(), 'milestone_not_actionable', $e);
        }

        return response()->json([
            'message' => 'Milestone confirmed.',
            'data' => new TradeAssuranceResource($agreement->refresh()->load('milestones')),
        ]);
    }
}
