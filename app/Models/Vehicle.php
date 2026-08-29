<?php

namespace App\Models;

use App\Models\Concerns\HasDocuments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company-owned vehicle in the fleet registry (gap-plan item 1.5.12).
 *
 * Compliance paperwork (registration, insurance, roadworthiness) is
 * deliberately NOT a bespoke column set here -- it rides the shared
 * polymorphic Document store via HasDocuments (see app/Models/Document.php),
 * so expiry alerts come for free from the existing
 * SendDocumentExpiryReminderJob rather than a second parallel system.
 */
class Vehicle extends Model
{
    use HasDocuments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'capacity_tonnes' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
