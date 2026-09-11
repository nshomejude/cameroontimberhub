<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CompanyReviewResource;
use App\Models\CompanyReview;
use App\Services\BuyerApiScope;
use App\Services\CompanyReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Buyer order-review over token auth — the API counterpart of the web
 * "leave a review" flow, mirroring `ReorderController`'s two-method shape
 * (see that controller's docblock for the pattern this follows).
 *
 * `{orderReference}` is resolved through `BuyerApiScope::order()`, the same
 * ownership boundary every other `orders/{orderReference}/...` route in this
 * group uses: another buyer's order 404s here too, never 403s.
 *
 * `CompanyReviewService` already IS the authority on eligibility and the
 * one-review-per-order rule (backed by a UNIQUE index on `order_id`) — this
 * controller adds nothing to either, it only reshapes what the service
 * already decides into HTTP:
 *
 *  - `eligibility()` is a read, so it is always `200`. `data.reason` carries
 *    `assertEligible()`'s real refusal message when `eligible` is false, and
 *    `data.existing_review` is populated whenever `hasReview()` is true, so a
 *    client that already submitted can render "you already reviewed this"
 *    instead of a blank form.
 *  - `store()` wraps `CompanyReviewService::create()` verbatim. That method
 *    calls `assertEligible()` itself before writing, so there is no
 *    redundant controller-side guard that could diverge from the service's
 *    real rule. Any `RuntimeException` it throws — order not completed yet,
 *    already reviewed, or the DB unique index catching a lost race — is the
 *    same real domain refusal `eligibility()` would have reported ahead of
 *    time, surfaced here as `422 review_not_eligible`.
 */
class CompanyReviewController extends Controller
{
    public function __construct(
        private readonly BuyerApiScope $scope,
        private readonly CompanyReviewService $reviews,
    ) {}

    /**
     * Eligibility check. NOT a write — never mutates anything.
     *
     * `data.existing_review` is the buyer's own prior review on this order
     * (`CompanyReviewResource`), or `null` when none exists yet.
     */
    public function eligibility(Request $request, string $orderReference): JsonResponse
    {
        $buyer = $request->user();
        $order = $this->scope->order($buyer, $orderReference);

        $eligible = $this->reviews->canReview($buyer, $order);
        $reason = null;

        if (! $eligible) {
            try {
                $this->reviews->assertEligible($buyer, $order);
            } catch (RuntimeException $e) {
                // The real refusal message assertEligible() throws (e.g.
                // "You can review a supplier once the order is completed." /
                // "You have already reviewed this order."), not an invented
                // generic one.
                $reason = $e->getMessage();
            }
        }

        $existing = CompanyReview::where('order_id', $order->getKey())->first();

        return response()->json([
            'data' => [
                'eligible' => $eligible,
                'reason' => $reason,
                'existing_review' => $existing ? new CompanyReviewResource($existing) : null,
            ],
        ]);
    }

    /**
     * The write: wraps `CompanyReviewService::create()`.
     *
     * Body: `rating` (1-5, required) + `body` (the review text — the real
     * field name on `CompanyReview`, see its model/service docblocks;
     * optional, matches the service's own `trim(...) ?: null` handling).
     */
    public function store(Request $request, string $orderReference): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'body' => ['nullable', 'string', 'max:5000'],
        ]);

        $buyer = $request->user();
        $order = $this->scope->order($buyer, $orderReference);
        $conversation = $order->conversation()->first();

        try {
            $review = $this->reviews->create($order, $buyer, $data, $conversation);
        } catch (RuntimeException $e) {
            throw new ApiException(422, 'review_not_eligible', $e->getMessage(), previous: $e);
        }

        return response()->json([
            'data' => new CompanyReviewResource($review),
        ], 201);
    }
}
