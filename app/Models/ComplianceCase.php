<?php

namespace App\Models;

use App\Enums\ComplianceCaseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Blueprint §17 Compliance Workflow: a case opened against any owning
 * entity (Company today; TimberLot, Shipment as those need assessment).
 * Columns are owner_type/owner_id (the dominant polymorphic convention in
 * this codebase -- see Capacity/Certificate/Document), but the relation
 * itself is named entity() per this feature's spec.
 */
class ComplianceCase extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ComplianceCaseStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function entity(): MorphTo
    {
        return $this->morphTo('owner', 'owner_type', 'owner_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
