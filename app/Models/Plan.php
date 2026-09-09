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
        ];
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
