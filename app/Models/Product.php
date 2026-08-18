<?php

namespace App\Models;

use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A supplier's marketplace listing (e.g. "Iroko Sawn Timber KD 50mm").
 * Products belong to a company and optionally reference a catalogued species.
 */
class Product extends Model
{
    use HasFactory, HasSlug, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'price_unit' => PriceUnit::class,
            'moq_unit' => PriceUnit::class,
            'status' => ProductStatus::class,
            'is_featured' => 'boolean',
            'is_best_seller' => 'boolean',
            'price_amount' => 'decimal:2',
            'moq_quantity' => 'decimal:2',
            'rating' => 'decimal:1',
            'specifications' => 'array',
            'key_benefits' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function species(): BelongsTo
    {
        return $this->belongsTo(Species::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }
}
