<?php

namespace App\Models;

use App\Enums\LogExportStatus;
use App\Enums\TimberCategory;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A timber species in the Cameroon catalogue.
 *
 * `commercial_category` holds a COMMERCIAL / MARKET grouping (see
 * App\Enums\TimberCategory) — it is not a MINFOF tax class or any other legal
 * classification. `log_export_status` and `is_promoted` are informational and
 * default to unverified; both must be checked against current MINFOF
 * publications before being surfaced as regulatory guidance.
 */
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
            'commercial_category' => TimberCategory::class,
            'log_export_status' => LogExportStatus::class,
            'is_promoted' => 'boolean',
            'typical_uses' => 'array',
            'region_availability' => 'array',
            'density_kg_m3_min' => 'integer',
            'density_kg_m3_max' => 'integer',
            'janka_hardness' => 'integer',
        ];
    }

    /** "450–650 kg/m³", or null when no density is recorded. */
    public function densityRange(): ?string
    {
        $min = $this->density_kg_m3_min;
        $max = $this->density_kg_m3_max;

        if ($min === null && $max === null) {
            return null;
        }

        if ($min !== null && $max !== null && $min !== $max) {
            return "{$min}–{$max} kg/m³";
        }

        return ($min ?? $max).' kg/m³';
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

    public function scopeInCategory(Builder $query, TimberCategory|string $category): Builder
    {
        return $query->where('commercial_category', $category instanceof TimberCategory ? $category->value : $category);
    }

    public function scopePromoted(Builder $query): Builder
    {
        return $query->where('is_promoted', true);
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
