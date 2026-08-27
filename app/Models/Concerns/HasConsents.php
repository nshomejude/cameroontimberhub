<?php

namespace App\Models\Concerns;

use App\Enums\ConsentPurpose;
use App\Models\Consent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model a polymorphic `consents` relation over the shared Consent
 * ledger. Add `use HasConsents;` to any model that needs revocable,
 * inspectable consent — see app/Models/Consent.php for the full contract.
 */
trait HasConsents
{
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'subject');
    }

    /** @return Collection<int, Consent> */
    public function activeConsents(): Collection
    {
        return $this->consents()->active()->get();
    }

    public function hasActiveConsent(ConsentPurpose $purpose): bool
    {
        return $this->consents()->active()->where('purpose', $purpose->value)->exists();
    }
}
