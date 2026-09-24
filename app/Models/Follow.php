<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A user (`follower_id`) following a Company or another User. Simple
 * pivot-style model — `followable()` mirrors `Capacity::owner()`'s MorphTo
 * pattern, the closest existing polymorphic-relation precedent in this
 * codebase.
 */
class Follow extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    public function followable(): MorphTo
    {
        return $this->morphTo();
    }
}
