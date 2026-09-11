<?php

namespace App\Http\Resources\Api\V1;

use App\Models\CompanyReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A buyer's own review of a supplier, for the buyer mobile app.
 *
 * `status`/`status_label` are the real `CompanyReviewStatus` value/label — a
 * review starts `published` (see `CompanyReviewService::create()`), so this
 * API never invents a "pending" state that doesn't exist; the field is
 * still surfaced honestly in case moderation later takes it down.
 *
 * @mixin CompanyReview
 */
class CompanyReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rating' => $this->rating,
            'body' => $this->body,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
