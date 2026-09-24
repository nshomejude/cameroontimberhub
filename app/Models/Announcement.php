<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dismissible feed item for the mobile app home screen (e.g. a seasonal
 * promo or a maintenance notice), with an optional in-app CTA that names a
 * mobile screen id rather than a URL — see the `announcements` migration's
 * docblock for why this is its own model rather than a reuse of `Page`.
 */
class Announcement extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Live right now: flagged active AND inside its (optional) scheduling
     * window. A null `starts_at`/`ends_at` means that side of the window is
     * open-ended.
     */
    public function scopeActive(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= now();

        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $at));
    }

    /** Same public-asset convention as Company::logoUrl()/Product image fields. */
    public function imageUrl(): ?string
    {
        $path = $this->image_path ? ltrim($this->image_path, '/') : null;

        return $path && is_file(public_path('img/'.$path)) ? asset('img/'.$path) : null;
    }
}
