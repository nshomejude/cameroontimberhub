<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentAccessLog extends Model
{
    /** Append-only event log: created_at only. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(CompanyDocument::class, 'company_document_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
