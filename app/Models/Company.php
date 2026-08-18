<?php

namespace App\Models;

use App\Enums\BadgeStatus;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Enums\SubscriptionStatus;
use App\Models\Concerns\HasSlug;
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
    use HasFactory, HasSlug, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => CompanyStatus::class,
            'sigif_permit_numbers' => 'array',
            'is_featured' => 'boolean',
            'verified_at' => 'datetime',
            'verification_expires_at' => 'datetime',
            'annual_capacity_m3' => 'decimal:2',
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

    /** Status-agnostic scope for the exporter dashboard: only the user's company. */
    public function scopeDashboardOwned(Builder $query, User $user): Builder
    {
        return $query->whereHas('users', fn (Builder $u) => $u->whereKey($user->getKey()));
    }

    public function activeBadges(): HasMany
    {
        return $this->verificationBadges()
            ->where('status', BadgeStatus::Active->value)
            ->where(fn (Builder $e) => $e->whereNull('valid_until')->orWhere('valid_until', '>', now()));
    }
}
