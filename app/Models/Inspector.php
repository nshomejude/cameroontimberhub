<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inspector onboarding profile (blueprint §26). An inspector is a User with
 * this extra profile. Not eligible to be assigned inspections unless
 * identity_verified_at is set AND agreement_accepted_at is set AND
 * status = 'active' -- see scopeEligible().
 */
class Inspector extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'identity_verified_at' => 'datetime',
            'agreement_accepted_at' => 'datetime',
            'professional_credentials' => 'array',
            'coverage_regions' => 'array',
            'inspection_categories' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query
            ->whereNotNull('identity_verified_at')
            ->whereNotNull('agreement_accepted_at')
            ->where('status', 'active');
    }

    public function isEligible(): bool
    {
        return $this->identity_verified_at !== null
            && $this->agreement_accepted_at !== null
            && $this->status === 'active';
    }

    public function coverageIncludes(string $region): bool
    {
        return in_array($region, $this->coverage_regions ?? [], true);
    }

    public function qualifiedFor(string $inspectionType): bool
    {
        return in_array($inspectionType, $this->inspection_categories ?? [], true);
    }
}
