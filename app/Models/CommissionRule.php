<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A single configurable marketplace-commission rule (billing engine M7,
 * plan §15).
 *
 * The rate, cap, tier scoping and effective window of every commission the
 * platform takes on a protected-trade order live here — never in code.
 * `CommissionCalculator` resolves the most specific active rule for a
 * segment/plan-tier at a point in time.
 *
 * `segment` mirrors `Plan.segment` (null = every segment). `plan_tier` is a
 * free string matched against `Plan.slug` (null = every tier in the
 * segment) — kept a free string rather than a FK so a rule can target a
 * tier concept ("free", "starter", "pro", "enterprise") without depending
 * on any one segment's exact slug set.
 *
 * ── Historical immutability (plan §2) ──────────────────────────────────
 * Once a rule is active it may have been applied to real orders. Silently
 * editing its rate/cap would retroactively rewrite the commission of past
 * transactions, so `updating` THROWS a RuntimeException when
 * `domestic_rate`, `international_rate`, `cap_amount` or `cap_percent`
 * changes on a rule that is currently active. The supported way to change a
 * rate is to create a NEW `commission_rules` row with a later
 * `effective_from` (and, optionally, close the old one with
 * `effective_until`). `CommissionCalculator` then resolves the correct rule
 * for any given `$at` date — historical dates keep resolving the old rule,
 * and an already-charged order's snapshot never changes regardless.
 *
 * Every create/update/delete is written to the `commission_rule` activity
 * log (no hash chain needed — a plain activity log matches `TaxRule`).
 */
class CommissionRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'domestic_rate' => 'decimal:4',
            'international_rate' => 'decimal:4',
            'cap_amount' => 'decimal:2',
            'cap_percent' => 'decimal:4',
            'is_active' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CommissionRule $rule): void {
            $rule->created_by ??= auth()->id();
            $rule->updated_by ??= auth()->id();
        });

        static::updating(function (CommissionRule $rule): void {
            $guarded = ['domestic_rate', 'international_rate', 'cap_amount', 'cap_percent'];

            if ($rule->getOriginal('is_active') && $rule->isDirty($guarded)) {
                throw new RuntimeException(
                    'The rate/cap of an active commission rule cannot be edited in place — it may already have been '
                    .'applied to orders. Create a new commission rule with a later effective_from instead (see '
                    .'App\\Models\\CommissionRule docblock).'
                );
            }

            $rule->updated_by = auth()->id() ?? $rule->updated_by;
        });

        static::created(fn (CommissionRule $rule) => self::audit($rule, 'created'));
        static::updated(fn (CommissionRule $rule) => self::audit($rule, 'updated'));
        static::deleted(fn (CommissionRule $rule) => self::audit($rule, 'deleted'));
    }

    private static function audit(CommissionRule $rule, string $event): void
    {
        activity('commission_rule')
            ->performedOn($rule)
            ->causedBy(auth()->user())
            ->event($event)
            ->withProperties($rule->only([
                'name', 'segment', 'plan_tier', 'domestic_rate', 'international_rate',
                'cap_amount', 'cap_percent', 'is_active', 'effective_from', 'effective_until',
            ]))
            ->log("Commission rule {$event}");
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
