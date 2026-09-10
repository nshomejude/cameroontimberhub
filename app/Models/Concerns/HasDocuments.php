<?php

namespace App\Models\Concerns;

use App\Models\Document;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model a polymorphic `documents` relation over the shared Document
 * store. Add `use HasDocuments;` to any model that needs verifiable,
 * expiring, hashed document attachments — see app/Models/Document.php for
 * the full contract.
 */
trait HasDocuments
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'owner');
    }

    /** @return Collection<int, Document> */
    public function verifiedDocuments(): Collection
    {
        return $this->documents()->verified()->get();
    }

    /**
     * A single word describing the compliance-paperwork state of this record,
     * derived straight off the shared Document store (no bespoke expiry
     * system): 'none' | 'expired' | 'expiring' (within 30 days) | 'ok'.
     * Uses the already-loaded `documents` relation when present so a table
     * column costs no extra query.
     */
    public function documentComplianceState(): string
    {
        $documents = $this->relationLoaded('documents') ? $this->documents : $this->documents()->get();

        if ($documents->isEmpty()) {
            return 'none';
        }

        if ($documents->contains(fn (Document $d): bool => $d->isExpired())) {
            return 'expired';
        }

        $expiringSoon = $documents->contains(
            fn (Document $d): bool => $d->expires_at !== null
                && ! $d->isExpired()
                && $d->expires_at->diffInDays(now()) <= 30,
        );

        return $expiringSoon ? 'expiring' : 'ok';
    }
}
