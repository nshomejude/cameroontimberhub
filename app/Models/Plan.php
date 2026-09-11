<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'price_amount' => 'decimal:2',
            'is_active' => 'boolean',
            'trial_days' => 'integer',
        ];
    }

    /** True when this plan offers an opt-in free trial (billing engine M6, §7.5). */
    public function hasTrial(): bool
    {
        return (int) ($this->trial_days ?? 0) > 0;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeForSegment(Builder $query, string $segment): Builder
    {
        return $query->where('segment', $segment);
    }

    public function feature(string $key, mixed $default = null): mixed
    {
        return data_get($this->features, $key, $default);
    }

    /**
     * Segments whose plans can be bought self-serve at launch (billing plan
     * §7.2). XAF segments settle via MTN MoMo / Orange Money; USD segments
     * via PayPal. `analyze` and `learn` are not sold through checkout yet;
     * enterprise/institutional is always a manual, negotiated assignment.
     */
    public const SELF_SERVE_SEGMENTS = ['sell', 'buy', 'deal', 'verify-comply', 'export', 'buy-international'];

    public function isFree(): bool
    {
        return (float) $this->price_amount <= 0.0;
    }

    /** Named/slugged "enterprise" tiers are sold as negotiated contracts, not via checkout. */
    public function isEnterprisePlan(): bool
    {
        return str_contains(strtolower((string) $this->slug), 'enterprise');
    }

    /** True when this plan can be purchased through the self-serve checkout flow. */
    public function isSelfServe(): bool
    {
        return (bool) $this->is_active
            && ! $this->isFree()
            && ! $this->isEnterprisePlan()
            && in_array($this->segment, self::SELF_SERVE_SEGMENTS, true)
            && $this->billing_period !== 'once';
    }

    /**
     * The payment providers offered for this plan, decided by its currency
     * (billing plan §7.2). XAF → mobile money; USD → PayPal.
     *
     * @return list<\App\Enums\PaymentProvider>
     */
    public function checkoutProviders(): array
    {
        return match (strtoupper((string) $this->price_currency)) {
            'XAF' => [\App\Enums\PaymentProvider::MtnMomo, \App\Enums\PaymentProvider::OrangeMoney],
            'USD' => [\App\Enums\PaymentProvider::PayPal],
            default => [],
        };
    }

    /**
     * API-First plan Phase 3 follow-up (see config/api.php doc block):
     * the API rate-limit tier ('basic'/'standard'/'elevated') this plan
     * maps to, used at API-key issuance time instead of a hardcoded value.
     * Falls back to config('api.rate_limit_tiers.default') for any plan
     * slug not explicitly mapped (e.g. non-API-relevant segments).
     */
    public function apiRateLimitTier(): string
    {
        return (string) (
            config("api.rate_limit_tiers.by_plan_slug.{$this->slug}")
            ?? config('api.rate_limit_tiers.default', 'basic')
        );
    }
}
