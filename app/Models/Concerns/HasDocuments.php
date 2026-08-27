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
}
