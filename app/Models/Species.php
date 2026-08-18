<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Species extends Model
{
    use HasFactory, HasSlug;

    protected $table = 'species';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'local_names' => 'array',
            'trade_names' => 'array',
            'characteristics' => 'array',
            'is_cites_listed' => 'boolean',
            'is_published' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function slugSourceColumn(): string
    {
        return 'common_name';
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_species')
            ->withPivot(['form', 'grade', 'min_order_m3', 'price_amount', 'price_currency', 'is_primary'])
            ->withTimestamps();
    }
}
