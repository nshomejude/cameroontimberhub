<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A single configurable tax rule (billing engine M5, plan §7.3 / §20).
 *
 * The rate, jurisdiction and effective window of every tax the platform
 * charges live here — never in code. `TaxCalculator` resolves the most
 * specific active rule for a jurisdiction/segment at a point in time.
 *
 * ── Historical immutability (plan §2) ──────────────────────────────────
 * Once a rule is active it may have been applied to real payments. Silently
 * editing its `rate` would retroactively rewrite the tax of past
 * transactions, so `updating` THROWS a RuntimeException when `rate` changes
 * on a rule that is currently active. The supported way to change a rate is
 * to create a NEW `tax_rules` row with a later `effective_from` (and,
 * optionally, close the old one with `effective_until`). `TaxCalculator`
 * then resolves the correct rule for any given `$at` date — historical
 * dates keep resolving the old rule.
 *
 * Every create/update/delete is written to the `tax_rule` activity log.
 */
class TaxRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (TaxRule $rule): void {
            $rule->created_by ??= auth()->id();
            $rule->updated_by ??= auth()->id();
        });

        static::updating(function (TaxRule $rule): void {
            if ($rule->getOriginal('is_active') && $rule->isDirty('rate')) {
                throw new RuntimeException(
                    'The rate of an active tax rule cannot be edited in place — it may already have been applied to payments. '
                    .'Create a new tax rule with a later effective_from instead (see App\\Models\\TaxRule docblock).'
                );
            }

            $rule->updated_by = auth()->id() ?? $rule->updated_by;
        });

        static::created(fn (TaxRule $rule) => self::audit($rule, 'created'));
        static::updated(fn (TaxRule $rule) => self::audit($rule, 'updated'));
        static::deleted(fn (TaxRule $rule) => self::audit($rule, 'deleted'));
    }

    private static function audit(TaxRule $rule, string $event): void
    {
        activity('tax_rule')
            ->performedOn($rule)
            ->causedBy(auth()->user())
            ->event($event)
            ->withProperties($rule->only(['name', 'jurisdiction', 'rate', 'applies_to', 'is_active', 'effective_from', 'effective_until']))
            ->log("Tax rule {$event}");
    }

    /**
     * Rules that are switched on AND whose effective window contains $at
     * (default: now). `effective_from`/`effective_until` null = open-ended.
     */
    public function scopeActive(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        $at = $at ? \Illuminate\Support\Carbon::instance($at) : now();

        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn (Builder $q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $at));
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
