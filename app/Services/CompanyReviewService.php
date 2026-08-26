<?php

namespace App\Services;

use App\Enums\CompanyReviewStatus;
use App\Models\Company;
use App\Models\CompanyReview;
use App\Models\Conversation;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Supplier reviews: who may leave one, and what a rating actually means.
 *
 * Earlier phases refused to invent review content, leaving `companies.rating_avg`
 * and `rating_count` as columns nothing wrote. This service is what makes them
 * honest — from here on they are recomputed by recompute() from the published
 * rows in `company_reviews` and are never set by hand anywhere else.
 *
 * Eligibility is deliberately narrow and checked against the ORDER, not against
 * a conversation or a company. To review a supplier you must own an order with
 * that supplier and that order must be `completed`. The database backs the
 * one-per-order rule with a UNIQUE index on `order_id`, so two concurrent
 * submissions cannot both land.
 */
class CompanyReviewService
{
    /* --------------------------------------------------------- eligibility */

    /**
     * Whether $user may review the supplier on $order right now.
     *
     * Three conditions, all necessary: they are the buyer on the order, the
     * order is completed, and no review exists for it yet.
     */
    public function canReview(?User $user, Order $order): bool
    {
        if ($user === null) {
            return false;
        }

        return (int) $order->user_id === (int) $user->getKey()
            && $order->isReviewable()
            && ! $this->hasReview($order);
    }

    public function hasReview(Order $order): bool
    {
        return CompanyReview::where('order_id', $order->getKey())->exists();
    }

    /**
     * The same three conditions, but throwing, so a caller cannot proceed by
     * forgetting to check. The messages distinguish the cases for the buyer's
     * benefit — none of them leak anything they cannot already see.
     */
    public function assertEligible(User $user, Order $order): void
    {
        // Not your order: this is an authorisation failure, not a domain
        // refusal, so it aborts rather than throwing a message into the UI.
        abort_unless((int) $order->user_id === (int) $user->getKey(), 403, 'Only the buyer on an order may review the supplier.');

        if (! $order->isReviewable()) {
            throw new RuntimeException('You can review a supplier once the order is completed.');
        }

        if ($this->hasReview($order)) {
            throw new RuntimeException('You have already reviewed this order.');
        }
    }

    /* ------------------------------------------------------------ writing */

    /**
     * Record a review and refresh the supplier's aggregates.
     *
     * @param  array{rating: int|string, title?: ?string, body?: ?string}  $data
     */
    public function create(Order $order, User $buyer, array $data, ?Conversation $conversation = null): CompanyReview
    {
        $this->assertEligible($buyer, $order);

        $rating = (int) ($data['rating'] ?? 0);

        if ($rating < 1 || $rating > 5) {
            throw new RuntimeException('A rating must be between 1 and 5 stars.');
        }

        return DB::transaction(function () use ($order, $buyer, $data, $rating, $conversation) {
            try {
                $review = CompanyReview::create([
                    'company_id' => $order->company_id,
                    'user_id' => $buyer->getKey(),
                    'order_id' => $order->getKey(),
                    'conversation_id' => $conversation?->getKey(),
                    'rating' => $rating,
                    'title' => Str::limit(trim((string) ($data['title'] ?? '')), 155, '') ?: null,
                    // Stored raw. Blade escapes it at render; nothing here
                    // strips tags, because stripping would silently mangle a
                    // legitimate "5 < 6" and escaping is the correct defence.
                    'body' => trim((string) ($data['body'] ?? '')) ?: null,
                    'status' => CompanyReviewStatus::Published->value,
                    'author_name' => $buyer->name,
                    'author_company' => $order->buyer_company,
                ]);
            } catch (QueryException $e) {
                // The UNIQUE index on order_id is the real guard; this turns a
                // lost race into the same message the PHP check would have
                // given rather than a 500.
                if ($this->isUniqueViolation($e)) {
                    throw new RuntimeException('You have already reviewed this order.');
                }

                throw $e;
            }

            $this->recompute($order->company);

            activity('order')->performedOn($order)->causedBy($buyer)
                ->event('reviewed')
                ->withProperties(['company_id' => $order->company_id, 'rating' => $rating])
                ->log('Buyer reviewed the supplier');

            return $review;
        });
    }

    /** Moderation. Changing status always re-derives the aggregates. */
    public function setStatus(CompanyReview $review, CompanyReviewStatus $status): CompanyReview
    {
        $review->update(['status' => $status->value]);

        $this->recompute($review->company);

        return $review->refresh();
    }

    /* -------------------------------------------------------- aggregates */

    /**
     * Recompute `rating_avg` / `rating_count` from the published rows.
     *
     * With no published reviews both columns go back to null/0 rather than to
     * "0.0 out of 5" — Company::hasRating() then hides the star strip entirely,
     * which is the behaviour the earlier phases already relied on.
     */
    public function recompute(?Company $company): void
    {
        if ($company === null) {
            return;
        }

        $row = CompanyReview::query()
            ->published()
            ->where('company_id', $company->getKey())
            ->selectRaw('COUNT(*) AS c, AVG(rating) AS a')
            ->first();

        $count = (int) ($row->c ?? 0);

        $company->forceFill([
            'rating_count' => $count,
            'rating_avg' => $count > 0 ? round((float) $row->a, 1) : null,
        ])->save();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? '') === '23505' || str_contains($e->getMessage(), 'Unique violation');
    }
}
