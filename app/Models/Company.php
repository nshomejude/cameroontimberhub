<?php

namespace App\Models;

use App\Enums\BadgeStatus;
use App\Enums\BadgeType;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Enums\DocumentStatus;
use App\Enums\DocumentVisibility;
use App\Enums\OrganisationType;
use App\Enums\ProductType;
use App\Enums\SubscriptionStatus;
use App\Enums\SupplierType;
use App\Models\Concerns\HasCapacities;
use App\Models\Concerns\HasSlug;
use App\Models\Concerns\HasVerification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use HasCapacities, HasFactory, HasSlug, HasVerification, SoftDeletes;

    protected $guarded = ['id'];

    /**
     * The `companies` columns needed to render a supplier card — the summary
     * shape embedded in product listings, quote payloads and the supplier
     * directory.
     *
     * It lives on the model because it is a statement about this table, and
     * because the query layer (ProductCatalogueService, SearchService) must be
     * able to name it without depending on the HTTP layer that consumes it.
     *
     * A partial select is the failure mode this exists to prevent: a column
     * left out here comes back `null` from the relation, and the presenter
     * cannot tell "unverified" from "never loaded" — so the same key silently
     * means two different things in a list payload and in a detail payload.
     *
     * @var list<string>
     */
    public const CARD_COLUMNS = [
        'id', 'slug', 'legal_name', 'trade_name', 'city', 'region', 'country_code',
        'status', 'logo_path', 'supplier_type', 'is_featured', 'verified_at',
        'years_experience', 'response_rate_percent', 'orders_completed',
        'rating_avg', 'rating_count',
    ];

    /**
     * Eager-load spec for a supplier-card relation, e.g.
     * `Product::with(Company::cardEagerLoad())`.
     */
    public static function cardEagerLoad(string $relation = 'company'): string
    {
        return $relation.':'.implode(',', self::CARD_COLUMNS);
    }

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'supplier_type' => SupplierType::class,
            'type' => OrganisationType::class,
            'response_rate_percent' => 'integer',
            'years_experience' => 'integer',
            'rating_avg' => 'decimal:1',
            'rating_count' => 'integer',
            'orders_completed' => 'integer',
            'response_time_hours' => 'integer',
            'languages' => 'array',
            'sigif_permit_numbers' => 'array',
            'is_featured' => 'boolean',
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'annual_capacity_m3' => 'decimal:2',
            'annual_harvest_capacity_m3' => 'decimal:2',
            'on_time_delivery_percent' => 'integer',
            'delivery_days_min' => 'integer',
            'delivery_days_max' => 'integer',
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected function slugSourceColumn(): string
    {
        return 'name';
    }

    /** Display name (spec): trade_name falls back to legal_name. No `name` column. */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => $this->trade_name ?: $this->legal_name);
    }

    /** Public URL for the company logo, falling back to the brand mark. */
    public function logoUrl(): string
    {
        return $this->publicImage($this->logo_path) ?? asset('brand/icon-96.png');
    }

    /**
     * Card cover photo. Uses the company's own cover when one is set, otherwise
     * a deterministic pick from the shared timber photo pool so a given company
     * always shows the same image.
     */
    public function coverUrl(): string
    {
        if ($url = $this->publicImage($this->cover_path)) {
            return $url;
        }

        $pool = [
            'products/iroko-logs.jpg',
            'products/iroko-sawn-timber.jpg',
            'products/sapele-veneer.jpg',
            'products/ayous-plywood.jpg',
            'products/tali-flooring.jpg',
            'products/azobe-decking.jpg',
            'products/padouk-mouldings.jpg',
            'products/iroko-sawn-timber-38.jpg',
        ];

        return asset('img/'.$pool[((int) $this->getKey()) % count($pool)]);
    }

    /** Resolve a path under public/img, or null when the file is absent. */
    private function publicImage(?string $path): ?string
    {
        $path = $path ? ltrim($path, '/') : null;

        return $path && is_file(public_path('img/'.$path)) ? asset('img/'.$path) : null;
    }

    // ---- Relations ------------------------------------------------------

    public function contacts(): HasMany
    {
        return $this->hasMany(CompanyContact::class)->orderBy('sort_order');
    }

    public function gallery(): HasMany
    {
        return $this->hasMany(CompanyGallery::class)->orderBy('sort_order');
    }

    public function exportMarkets(): HasMany
    {
        return $this->hasMany(CompanyExportMarket::class);
    }

    public function socialLinks(): HasMany
    {
        return $this->hasMany(CompanySocialLink::class);
    }

    public function verificationBadges(): HasMany
    {
        return $this->hasMany(VerificationBadge::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CompanyDocument::class);
    }

    public function verificationRequests(): HasMany
    {
        return $this->hasMany(VerificationRequest::class);
    }

    public function inquiries(): HasMany
    {
        return $this->hasMany(CompanyInquiry::class);
    }

    public function rfqRoutings(): HasMany
    {
        return $this->hasMany(RfqCompany::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', SubscriptionStatus::Active->value)
            ->latestOfMany();
    }

    /** @return array<string, mixed> */
    public function planFeatures(): array
    {
        return (array) ($this->plan?->features ?? []);
    }

    /** Plan-based feature gate (spec: plans-as-data + manual assignment). */
    public function hasFeature(string $key): bool
    {
        return (bool) data_get($this->planFeatures(), $key, false);
    }

    /**
     * The numeric gallery-image cap for this company's active plan
     * (docs/PRICING_SPEC.md §5's `max_gallery` feature). hasFeature() is
     * boolean-shaped and wrong for a count -- this reads the same
     * planFeatures() data but as an int, defaulting to the Free plan's
     * documented limit (3) for a company with no plan or a plan missing
     * the key entirely, never 0 or unlimited by omission.
     */
    public function maxGalleryImages(): int
    {
        return (int) data_get($this->planFeatures(), 'max_gallery', 3);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function species(): BelongsToMany
    {
        return $this->belongsToMany(Species::class, 'company_species')
            ->withPivot(['form', 'grade', 'min_order_m3', 'price_amount', 'price_currency', 'is_primary'])
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_user')
            ->withPivot(['role', 'is_primary'])
            ->withTimestamps()
            ->withCasts(['role' => CompanyUserRole::class]);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ---- Scopes ---------------------------------------------------------

    /**
     * The SINGLE gate for public exposure (spec §1.3). Every public surface —
     * directory, profile page, species "exporters" list, sitemap, structured
     * data — must read through this scope. A company silently drops out the
     * moment it loses status, a required field, or its last active badge.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', CompanyStatus::Verified->value)
            ->whereNotNull('logo_path')
            ->whereNotNull('description')
            ->whereNotNull('region')
            ->whereHas('species')
            ->whereHas('contacts')
            ->whereHas('verificationBadges', fn (Builder $b) => $b
                ->where('status', BadgeStatus::Active->value)
                ->where(fn (Builder $e) => $e->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            );
    }

    /**
     * Directory facet: narrow to one or more commercial roles. An empty list is
     * a no-op so the scope can be chained unconditionally.
     *
     * @param  list<string>  $types  SupplierType values
     */
    public function scopeOfSupplierType(Builder $query, array $types): Builder
    {
        $types = array_values(array_intersect($types, SupplierType::values()));

        return $types === [] ? $query : $query->whereIn('supplier_type', $types);
    }

    /**
     * Directory facet: companies handling ANY of the given species slugs.
     *
     * @param  list<string>  $slugs
     */
    public function scopeHandlingSpecies(Builder $query, array $slugs): Builder
    {
        $slugs = array_values(array_filter($slugs));

        return $slugs === [] ? $query : $query->whereHas('species', fn (Builder $s) => $s->whereIn('species.slug', $slugs));
    }

    /**
     * Directory facet: companies listing at least one active product in ANY of
     * the given product types ("Products / Specialization" in the mockup).
     *
     * @param  list<string>  $productTypes  ProductType values
     */
    public function scopeWithSpecialisation(Builder $query, array $productTypes): Builder
    {
        $productTypes = array_values(array_intersect($productTypes, array_column(ProductType::cases(), 'value')));

        return $productTypes === []
            ? $query
            : $query->whereHas('products', fn (Builder $p) => $p->active()->whereIn('product_type', $productTypes));
    }

    /**
     * Directory facet: companies holding ANY of the given active badge types.
     *
     * @param  list<string>  $badgeTypes  BadgeType values
     */
    public function scopeWithCertifications(Builder $query, array $badgeTypes): Builder
    {
        $badgeTypes = array_values(array_intersect($badgeTypes, array_column(BadgeType::cases(), 'value')));

        return $badgeTypes === []
            ? $query
            : $query->whereHas('verificationBadges', fn (Builder $b) => $b
                ->whereIn('badge_type', $badgeTypes)
                ->where('status', BadgeStatus::Active->value)
            );
    }

    /** Directory facet: at least N years of trading experience. */
    public function scopeMinExperience(Builder $query, ?int $years): Builder
    {
        return $years === null || $years <= 0 ? $query : $query->where('years_experience', '>=', $years);
    }

    /**
     * Buyer reviews of this supplier. `rating_avg` / `rating_count` are
     * recomputed from the published rows here by CompanyReviewService, so the
     * relation is the source and the columns are the cache.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(CompanyReview::class);
    }

    public function publishedReviews(): HasMany
    {
        return $this->reviews()->published()->latest();
    }

    /**
     * True when the supplier has published settlement instructions. There is
     * no payment integration; this is free text the supplier maintains so a
     * buyer can pay them off-platform.
     */
    public function hasPaymentInstructions(): bool
    {
        return trim((string) $this->payment_instructions) !== '';
    }

    /** Status-agnostic scope for the exporter dashboard: only the user's company. */
    public function scopeDashboardOwned(Builder $query, User $user): Builder
    {
        return $query->whereHas('users', fn (Builder $u) => $u->whereKey($user->getKey()));
    }

    /**
     * True only when the supplier has a real buyer rating behind it. The
     * product page's star row is hidden entirely when this is false, rather
     * than rendering an empty five-star strip or "0 reviews".
     */
    public function hasRating(): bool
    {
        return $this->rating_avg !== null && (int) $this->rating_count > 0;
    }

    /** "< 2h" / "< 3 days", or null when no response time is recorded. */
    public function responseTimeLabel(): ?string
    {
        $hours = $this->response_time_hours;

        if ($hours === null) {
            return null;
        }

        return $hours < 24
            ? '< '.$hours.'h'
            : '< '.(int) ceil($hours / 24).' days';
    }

    /**
     * "Member Since" for the supplier card — the verification date, falling
     * back to when the record was created. Never invented.
     */
    public function memberSince(): ?string
    {
        return ($this->verified_at ?? $this->created_at)?->format('M Y');
    }

    /**
     * The direct-chat target behind the mobile "Chat with Supplier" button.
     *
     * Only PUBLIC contacts are considered, WhatsApp is preferred over a plain
     * telephone number, and null is returned when the supplier has published
     * neither — the button is then not rendered at all rather than shipped dead.
     *
     * @return array{channel: string, url: string, label: string}|null
     */
    public function chatLink(): ?array
    {
        $public = $this->contacts->filter(fn (CompanyContact $c) => $c->is_public);

        $digits = fn (?string $v): ?string => filled($v) ? (preg_replace('/\D+/', '', $v) ?: null) : null;

        foreach ($public as $contact) {
            if ($number = $digits($contact->whatsapp)) {
                return ['channel' => 'whatsapp', 'url' => 'https://wa.me/'.$number, 'label' => 'Chat with Supplier'];
            }
        }

        foreach ($public as $contact) {
            if ($number = $digits($contact->phone)) {
                return ['channel' => 'phone', 'url' => 'tel:+'.$number, 'label' => 'Call Supplier'];
            }
        }

        return null;
    }

    /**
     * "15 - 30 Days" for the logistics panel, or null when neither bound is
     * recorded. A single bound renders on its own rather than being padded out.
     */
    public function deliveryTimeLabel(): ?string
    {
        $min = $this->delivery_days_min;
        $max = $this->delivery_days_max;

        return match (true) {
            $min !== null && $max !== null => $min.' - '.$max.' days',
            $min !== null => 'from '.$min.' days',
            $max !== null => 'up to '.$max.' days',
            default => null,
        };
    }

    /** Public documents only — never the private/admin/buyer-gated ones. */
    public function publicDocuments(): HasMany
    {
        return $this->documents()
            ->where('visibility', DocumentVisibility::Public->value)
            ->where('status', DocumentStatus::Approved->value)
            ->where(fn (Builder $q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', today()))
            ->orderByDesc('issue_date');
    }

    public function activeBadges(): HasMany
    {
        return $this->verificationBadges()
            ->where('status', BadgeStatus::Active->value)
            ->where(fn (Builder $e) => $e->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }
}
