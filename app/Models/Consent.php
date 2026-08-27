<?php

namespace App\Models;

use App\Enums\ConsentPurpose;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A revocable, inspectable consent grant for any polymorphic subject.
 *
 * See database/migrations/2026_08_27_100010_create_consents_table.php for
 * the shape rationale (purpose enum, structured scope, evidence snapshot).
 *
 * Revocation is a first-class, enforced action: `scopeActive()` is the only
 * correct way to ask "does this subject currently have consent", and
 * `RfqTriageService::route()` uses exactly that scope to refuse routing an
 * RFQ whose consent has been revoked — see
 * docs/superpowers/plans/2026-08-27-persisted-consent.md, Task 4.
 */
class Consent extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'purpose' => ConsentPurpose::class,
            'scope' => 'array',
            'evidence' => 'array',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->where('subject_type', $subject::class)->where('subject_id', $subject->getKey());
    }
}
