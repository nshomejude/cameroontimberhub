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

    // ---- Presentation helpers -------------------------------------------

    /** Resolve a path under public/img, or null when the file is absent. */
    private function publicImage(?string $path): ?string
    {
        $path = $path ? ltrim($path, '/') : null;

        if ($path === null) {
            return null;
        }

        if (str_starts_with($path, 'http')) {
            return $path;
        }

        return is_file(public_path('img/'.$path)) ? asset('img/'.$path) : null;
    }

    /**
     * The gallery, in display order: the primary image first, then every
     * `product_images` row whose file actually exists on disk. Never padded
     * out with placeholders — a listing with one photo shows one photo.
     *
     * @return list<array{url: string, alt: string}>
     */
    public function galleryImages(): array
    {
        $out = [];
        $seen = [];

        $push = function (?string $path, ?string $alt) use (&$out, &$seen): void {
            $url = $this->publicImage($path);

            if ($url === null || isset($seen[$url])) {
                return;
            }

            $seen[$url] = true;
            $out[] = ['url' => $url, 'alt' => $alt ?: ''];
        };

        $push($this->primary_image_path, $this->name);

        if ($this->relationLoaded('images') || $this->exists) {
            foreach ($this->images as $image) {
                $push($image->path, $image->alt);
            }
        }

        return $out;
    }

    /** First gallery image, or null when the listing has no usable photo. */
    public function primaryImageUrl(): ?string
    {
        return $this->galleryImages()[0]['url'] ?? null;
    }

    /** "650,000 FCFA", or null when the listing is quote-on-request. */
    public function priceLabel(): ?string
    {
        if ($this->price_amount === null) {
            return null;
        }

        return number_format((float) $this->price_amount).' '.$this->currencyLabel();
    }

    /** XAF is written FCFA in-market; every other currency keeps its ISO code. */
    public function currencyLabel(): string
    {
        return $this->price_currency === 'XAF' ? 'FCFA' : (string) $this->price_currency;
    }

    /** "20 m³", or null when no minimum order is recorded. */
    public function moqLabel(): ?string
    {
        if ($this->moq_quantity === null) {
            return null;
        }

        $qty = rtrim(rtrim(number_format((float) $this->moq_quantity, 2), '0'), '.');

        return $qty.' '.$this->moq_unit->label();
    }

    /** True only when a real buyer rating exists behind the star row. */
    public function hasRating(): bool
    {
        return $this->rating !== null && (int) $this->reviews_count > 0;
    }

    /** "100mm – 250mm", "100mm", or null. */
    public function widthLabel(): ?string
    {
        return $this->rangeLabel($this->width_min_mm, $this->width_max_mm, 'mm');
    }

    /** "2.4m – 6.0m", "2.4m", or null. */
    public function lengthLabel(): ?string
    {
        return $this->rangeLabel($this->length_min_m, $this->length_max_m, 'm', 1);
    }

    private function rangeLabel(mixed $min, mixed $max, string $unit, int $decimals = 0): ?string
    {
        $fmt = fn ($v): string => rtrim(rtrim(number_format((float) $v, max($decimals, 1)), '0'), '.').$unit;

        if ($min === null && $max === null) {
            return null;
        }

        if ($min !== null && $max !== null && (float) $min !== (float) $max) {
            return $fmt($min).' – '.$fmt($max);
        }

        return $fmt($min ?? $max);
    }

    /**
     * The "Product Specifications" table.
     *
     * Rows are read from the linked Species record wherever the species holds
     * the fact (botanical name, trade names, density, durability, uses), and
     * from the listing's own `specifications` bag for the commercial rows the
     * supplier controls (packaging, delivery). Species facts win only where the
     * listing has not overridden them.
     *
     * @return list<array{label: string, value: string}>
     */
    public function specificationRows(): array
    {
        $own = collect(is_array($this->specifications) ? $this->specifications : [])
            ->mapWithKeys(fn ($v, $k) => [str($k)->headline()->lower()->value() => is_array($v) ? implode(', ', $v) : (string) $v]);

        $species = $this->species;

        $candidates = [
            'Botanical Name' => $species?->scientific_name,
            'Also Known' => $species && is_array($species->trade_names) ? implode(', ', $species->trade_names) : null,
            'Density' => $species?->densityRange(),
            'Durability Class' => $species?->durability_class,
            'Janka Hardness' => $species?->janka_hardness ? $species->janka_hardness.' N' : null,
            'Appearance' => is_array($species?->characteristics) ? ($species->characteristics['Colour'] ?? null) : null,
            'Common Uses' => $species && is_array($species->typical_uses) ? implode(', ', $species->typical_uses) : null,
        ];

        $rows = [];

        foreach ($candidates as $label => $value) {
            $key = str($label)->lower()->value();
            $resolved = $own[$key] ?? $value;

            if (filled($resolved)) {
                $rows[] = ['label' => $label, 'value' => (string) $resolved];
            }

            $own->forget($key);
        }

        // Anything else the supplier recorded (strength, workability, packaging,
        // delivery…) follows, in the order it was entered.
        foreach ($own as $key => $value) {
            if (filled($value)) {
                $rows[] = ['label' => str($key)->headline()->value(), 'value' => (string) $value];
            }
        }

        return $rows;
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
