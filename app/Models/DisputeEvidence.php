<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of evidence (a description, optionally with a file) submitted by a
 * party during a Dispute's Evidence Submission stage.
 *
 * Mirrors OrderDocument: the file lives on the PRIVATE `documents` disk and
 * is never exposed by a public URL accessor -- access is re-checked against
 * dispute party membership by the controller/download route, not trusted
 * from this row.
 */
class DisputeEvidence extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(Dispute::class);
    }

    public function submittedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function submittedByCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'submitted_by_company_id');
    }

    public function hasFile(): bool
    {
        return filled($this->storage_path);
    }
}
