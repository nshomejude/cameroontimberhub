<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Capacity extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:2'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Brief §4.3's "who can produce 5,000 chairs/month?" search: matches a
     * capability by partial text and a quantity/period pair that can
     * actually satisfy the request (this owner's capacity, normalized to
     * the same period, must be >= the requested quantity).
     */
    public function scopeMatching(Builder $query, string $capability, float $minQuantity, string $period): Builder
    {
        return $query
            ->where('capability', 'ilike', "%{$capability}%")
            ->where('period', $period)
            ->where('quantity', '>=', $minQuantity);
    }
}
