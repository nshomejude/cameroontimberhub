<?php

namespace App\Models;

use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
use App\Enums\OrganisationType;
use App\Enums\PriceUnit;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\HasVerification;
use App\Support\ProductIdentifier;
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
    use HasFactory, HasSlug, HasVerification, SoftDeletes;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Assign the public identifier (gap-plan §1.1) on create. Never
        // reassigns — getRouteKeyName() stays `slug`; this id is only used by
        // the QR code and the /verify/product/{publicId} route.
        static::creating(function (Product $product): void {
            if (blank($product->public_id)) {
                $product->public_id = ProductIdentifier::forProduct($product);
            }
        });
    }

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
            'custom_attributes' => 'array',
        ];
    }

    /** A single free-form attribute recorded on this listing (materials, finish, style, ...). */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return data_get($this->custom_attributes, $key, $default);
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
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

    /**
     * An INDICATIVE USD equivalent of the quoted price, e.g. "1,080".
     *
     * Returns null unless an operator-configured rate exists
     * (`timber.fx.usd_per_xaf`) and the listing is genuinely priced in XAF.
     * No rate is ever assumed — the UI simply drops the line.
     */
    public function indicativeUsdPrice(): ?string
    {
        $rate = config('timber.fx.usd_per_xaf');

        if (! is_numeric($rate) || (float) $rate <= 0) {
            return null;
        }

        if ($this->price_amount === null || $this->price_currency !== 'XAF') {
            return null;
        }

        return number_format((float) $this->price_amount * (float) $rate);
    }

    /**
     * The mobile trust strip. Each badge is derived from a real signal on the
     * listing, its species or its supplier; a badge with no backing signal is
     * simply absent rather than rendered as decoration.
     *
     * @return list<array{key: string, label: string, icon: string}>
     */
    public function trustBadges(): array
    {
        $company = $this->company;
        $species = $this->species;

        $legalPattern = '/legal origin|flegt|fsc|pefc|sustainab|sigif|cites/i';

        $certifiedBadge = $company
            ? $company->activeBadges
                ->contains(fn ($b) => in_array($b->badge_type, [
                    BadgeType::LegalTimberSupplier,
                    BadgeType::SigifRegistered,
                    BadgeType::SustainabilityProfile,
                    BadgeType::CitesApproved,
                ], true))
            : false;

        $exportReady = $company
            && ($company->exportMarkets->isNotEmpty()
                || $company->activeBadges->contains(fn ($b) => $b->badge_type === BadgeType::ExportReady));

        $reliable = $company
            && (((int) $company->response_rate_percent) >= 80 || ((int) $company->years_experience) >= 5);

        $signals = [
            // Sustainably Sourced ← a legal-origin / sustainability certification
            // recorded on the listing, or an equivalent active supplier badge.
            ['key' => 'sustainably-sourced', 'label' => 'Sustainably Sourced', 'icon' => 'sparkles',
                'on' => (filled($this->certification) && preg_match($legalPattern, (string) $this->certification) === 1) || $certifiedBadge],

            // Premium Quality ← a real grade on the listing, or a species the
            // catalogue classifies in the specialty / precious band.
            ['key' => 'premium-quality', 'label' => 'Premium Quality', 'icon' => 'star',
                'on' => filled($this->grade) || (bool) $species?->isPremium()],

            // Reliable Supply ← a real supplier responsiveness / longevity metric.
            ['key' => 'reliable-supply', 'label' => 'Reliable Supply', 'icon' => 'truck',
                'on' => $reliable],

            // Export Ready ← the supplier actually records export markets.
            ['key' => 'export-ready', 'label' => 'Export Ready', 'icon' => 'globe-europe-africa',
                'on' => $exportReady],
        ];

        return array_values(array_map(
            fn (array $s) => ['key' => $s['key'], 'label' => $s['label'], 'icon' => $s['icon']],
            array_filter($signals, fn (array $s) => (bool) $s['on'])
        ));
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
            'Materials Used' => $this->materials_used,
            'Finish' => $this->finish,
            'Dimensions' => $this->dimensions_description,
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

    /**
     * "Made in Cameroon" is a computed fact, not a manually-reviewed document
     * badge — it does not go through BadgeService/VerificationBadge. A
     * listing qualifies when its company is a verified, Cameroon-based
     * domestic-transformation business (manufacturer, processor or artisan —
     * not a raw-material supplier) and the listing itself is live.
     */
    public function qualifiesForMadeInCameroon(): bool
    {
        $company = $this->company;

        if (! $company || $this->status !== ProductStatus::Active) {
            return false;
        }

        return $company->country_code === 'CM'
            && $company->status === CompanyStatus::Verified
            && in_array($company->type, [
                OrganisationType::Manufacturer,
                OrganisationType::Processor,
                OrganisationType::Artisan,
            ], true);
    }

    /** Query-level equivalent of {@see qualifiesForMadeInCameroon()}, for listings. */
    public function scopeMadeInCameroon(Builder $query): Builder
    {
        return $query
            ->where('status', ProductStatus::Active)
            ->whereHas('company', fn (Builder $c) => $c
                ->where('country_code', 'CM')
                ->where('status', CompanyStatus::Verified->value)
                ->whereIn('type', [
                    OrganisationType::Manufacturer->value,
                    OrganisationType::Processor->value,
                    OrganisationType::Artisan->value,
                ]));
    }
}
