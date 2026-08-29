<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node in the additive product-category tree (gap-plan 1.5.1, brief §4.1).
 * `kind` distinguishes the two orthogonal top-level dimensions ("form" and
 * "sector") that currently share this table — see the categories migration
 * docblock for why they are not nested under each other.
 */
class Category extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
