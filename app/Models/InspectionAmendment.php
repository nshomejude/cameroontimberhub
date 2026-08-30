<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only amendment log entry for a finalised Inspection (blueprint
 * §27's "formal amended-report workflow"). Created only via
 * Inspection::amend() -- never edited or deleted directly.
 */
class InspectionAmendment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InspectionAmendment $amendment) {
            $amendment->created_at ??= now();
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function amendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'amended_by');
    }
}
