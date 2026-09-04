<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A daily point-in-time snapshot of a computed Market Intelligence index
 * value (blueprint §33-34). Written by the `market-intel:snapshot` console
 * command; never computed on the fly for historical trend charts.
 */
class MarketIntelligenceSnapshot extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'value' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function scopeOfType(Builder $query, string $indexType): Builder
    {
        return $query->where('index_type', $indexType);
    }

    public function scopeForDimension(Builder $query, string $dimension): Builder
    {
        return $query->where('dimension', $dimension);
    }
}
