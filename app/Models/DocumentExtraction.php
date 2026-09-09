<?php

namespace App\Models;

use App\Enums\DocumentExtractionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One AI extraction attempt against a CompanyDocument or OrderDocument.
 *
 * REVIEW AID ONLY (blueprint §35) — extracted_fields must never be read by
 * any code path that changes verification/compliance state. A human
 * (admin/compliance officer) reviews it and acts on the underlying document
 * themselves; reviewed_by/reviewed_at records only that a human looked.
 */
class DocumentExtraction extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'extraction_status' => DocumentExtractionStatus::class,
            'extracted_fields' => 'array',
            'extracted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }
}
