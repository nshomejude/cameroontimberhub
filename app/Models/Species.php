<?php

namespace App\Models;

use App\Enums\LogExportStatus;
use App\Enums\TimberCategory;
use App\Models\Concerns\HasDocuments;
use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
    use HasDocuments, HasFactory, HasSlug;

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
            // Knowledge System fields (SEO authority spec §C). All nullable —
            // a null value means "not recorded", never a fabricated fact.
            'taxonomy' => 'array',
            'treatments' => 'array',
            'grades_available' => 'array',
            'authoritative_sources' => 'array',
        ];
    }

    /**
     * `meta_description` is stored pre-truncated (see SpeciesSeeder), but
     * older/legacy rows may still hold a raw value cut mid-word by a plain
     * character-count limit. Re-truncating on read at a word boundary makes
     * every species page's rendered <meta name="description"> correct
     * regardless of how the stored value was produced.
     */
    protected function metaDescription(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : static::wordSafeExcerpt($value, 300),
        );
    }

    /**
     * Truncate to at most $limit characters without cutting a word in half.
     * Text already within the limit is returned unchanged; otherwise it is
     * cut back to the last preceding space and an ellipsis is appended.
     */
    public static function wordSafeExcerpt(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated, " \t\n\r\0\x0B.,;:").'...';
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

    /**
     * "Premium" merchandising flag used by the public directory.
     *
     * Driven purely by real data: the commercial category recorded for the
     * species. Specialty / precious woods are the premium band of the Cameroon
     * trade — small volumes, high prices. Nothing here is hand-curated.
     */
    public function isPremium(): bool
    {
        return $this->commercial_category === TimberCategory::Specialty;
    }

    /**
     * The grain swatch for a species card.
     *
     * Only eight photographed swatches exist in `public/img/species`, against
     * 52 catalogued species. Rather than reuse another species' photograph —
     * which would show visitors the wrong timber — species without a
     * photograph get a deterministic generated swatch whose colours are read
     * from the species' own recorded `characteristics['Colour']`. The result is
     * stable across requests and always a plausible, non-misleading stand-in.
     *
     * @return array{image: ?string, from: string, via: string, to: string}
     */
    public function swatch(): array
    {
        $image = null;

        if (filled($this->image_path)) {
            $image = str_starts_with((string) $this->image_path, 'http')
                ? $this->image_path
                : '/'.ltrim((string) $this->image_path, '/');
        } elseif (is_file(public_path('img/species/'.$this->slug.'.png'))) {
            $image = '/img/species/'.$this->slug.'.png';
        }

        return ['image' => $image] + $this->generatedSwatch();
    }

    /**
     * Colour keywords, most specific first, mapped to a light/mid/dark ramp.
     *
     * @return array{from: string, via: string, to: string}
     */
    private function generatedSwatch(): array
    {
        $ramps = [
            'black' => ['#4a423b', '#2b2521', '#17120f'],
            'purple' => ['#8b5f57', '#6b4038', '#4a2823'],
            'chocolate' => ['#8a5c3d', '#653c25', '#432616'],
            'blood red' => ['#b4462f', '#8e2f1e', '#661d10'],
            'deep red' => ['#b4462f', '#8e2f1e', '#661d10'],
            'orange-red' => ['#d0663a', '#a94724', '#7d2f14'],
            'red-brown' => ['#b57a52', '#8d5433', '#61371f'],
            'reddish brown' => ['#bd8058', '#955835', '#6a3b20'],
            'olive' => ['#b6a56a', '#8e7c45', '#615229'],
            'golden' => ['#dcab6a', '#bd8845', '#8c5f26'],
            'orange' => ['#dda06a', '#c07a3f', '#8e5522'],
            'salmon' => ['#e2b39a', '#c8886a', '#9c5f45'],
            'pink' => ['#e3bda9', '#c9967c', '#9d6a52'],
            'grey' => ['#c2bcb1', '#9a938a', '#6e6960'],
            'lemon' => ['#e6cf90', '#cbae5e', '#967c33'],
            'yellow' => ['#e3c68f', '#c5a15b', '#8f7132'],
            'straw' => ['#e9d7ad', '#d0b881', '#9e8a58'],
            'cream' => ['#eee0c4', '#dbc79c', '#ab9670'],
            'white' => ['#f0e7d6', '#ddcfb6', '#b0a287'],
            'brown' => ['#c39468', '#9b6d43', '#6d4826'],
        ];

        $colour = strtolower((string) (is_array($this->characteristics) ? ($this->characteristics['Colour'] ?? '') : ''));

        foreach ($ramps as $needle => $ramp) {
            if ($colour !== '' && str_contains($colour, $needle)) {
                return ['from' => $ramp[0], 'via' => $ramp[1], 'to' => $ramp[2]];
            }
        }

        // No recorded colour: fall back to a stable pick from the ramp table so
        // the card still reads as timber rather than an empty grey box.
        $ramp = array_values($ramps)[crc32((string) $this->slug) % count($ramps)];

        return ['from' => $ramp[0], 'via' => $ramp[1], 'to' => $ramp[2]];
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
