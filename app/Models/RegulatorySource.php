<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Blueprint §16 Regulatory Source Registry: the source record every
 * ComplianceRule must reference (enforced via restrictOnDelete FK on
 * compliance_rules).
 */
class RegulatorySource extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'last_checked_at' => 'date',
            'next_review_date' => 'date',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function complianceRules(): HasMany
    {
        return $this->hasMany(ComplianceRule::class);
    }
}
