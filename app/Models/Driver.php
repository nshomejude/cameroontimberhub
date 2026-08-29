<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-owned driver in the fleet registry (gap-plan item 1.5.12).
 *
 * Compliance paperwork (driving license, medical certificate, etc.) rides
 * the shared polymorphic Document store via HasDocuments (see
 * app/Models/Document.php) -- see Vehicle.php for the full rationale.
 */
class Driver extends Model
{
    use HasDocuments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
