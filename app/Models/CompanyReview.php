<?php

namespace App\Models;

use App\Enums\CompanyReviewStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A buyer's review of a supplier, anchored to the order that earned it.
 *
 * `order_id` is required and UNIQUE. That single index is the whole
 * anti-astroturfing story: a review cannot exist without a real order, and an
 * order can carry at most one. Eligibility (the order is completed, and this
 * account is its buyer) is decided in CompanyReviewService, not here.
 *
 * `body` is stored raw and escaped at render time by Blade. Nothing in this
 * class or its views ever emits it unescaped.
 */
class CompanyReview extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CompanyReviewStatus::class,
            'rating' => 'integer',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /* ------------------------------------------------------------ scopes */

    /** The only rows that count towards a rating or appear on a profile. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', CompanyReviewStatus::Published->value);
    }

    /* ----------------------------------------------------------- helpers */

    public function isPublished(): bool
    {
        return $this->status === CompanyReviewStatus::Published;
    }

    /** "Alain M." — the author's own name, initialised on the surname. */
    public function authorDisplayName(): string
    {
        $parts = preg_split('/\s+/u', trim((string) $this->author_name)) ?: [];

        if (count($parts) < 2) {
            return $this->author_name ?: 'Buyer';
        }

        $last = (string) array_pop($parts);

        return implode(' ', $parts).' '.mb_strtoupper(mb_substr($last, 0, 1)).'.';
    }
}
