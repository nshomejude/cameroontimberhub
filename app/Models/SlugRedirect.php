<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SlugRedirect extends Model
{
    protected $guarded = ['id'];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Record a 301 redirect from an old slug path to a new URL.
     * Idempotent: upserts on from_slug.
     */
    public static function record(string $fromSlug, string $toUrl, ?string $entityType = null, ?int $entityId = null, ?int $changedBy = null): static
    {
        return static::updateOrCreate(
            ['from_slug' => ltrim($fromSlug, '/')],
            [
                'to_url' => $toUrl,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'changed_by' => $changedBy,
            ],
        );
    }
}
