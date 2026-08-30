<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Inspection report (blueprint §27). Once finalised (finalised_at set),
 * report-content fields are immutable except through the formal amendment
 * workflow -- see amend(). finalise() computes and stores a digital
 * signature (a SHA-256 hash over canonical report content, in the same
 * style as App\Models\ChainedActivity) and pushes the result onto the
 * related TimberLot's inspection_status.
 */
class Inspection extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * Report-content fields protected by the immutability guard once
     * finalised_at is set. Anything not in this list (e.g. internal
     * bookkeeping columns, should any be added later) may still be updated
     * freely after finalisation.
     */
    public const CONTENT_FIELDS = [
        'inspector_id',
        'timber_lot_id',
        'order_id',
        'inspection_type',
        'scheduled_for',
        'performed_at',
        'location',
        'observed_quantity',
        'measured_dimensions',
        'moisture_results',
        'quality_findings',
        'species_findings',
        'packaging_findings',
        'photos',
        'videos',
        'documents_examined',
        'result',
        'inspector_notes',
    ];

    /** Internal flag set only by amend()/finalise() to permit a guarded write. */
    private bool $allowGuardedWrite = false;

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'date',
            'performed_at' => 'datetime',
            'finalised_at' => 'datetime',
            'observed_quantity' => 'decimal:2',
            'measured_dimensions' => 'array',
            'moisture_results' => 'array',
            'photos' => 'array',
            'videos' => 'array',
            'documents_examined' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Inspection $inspection) {
            if ($inspection->allowGuardedWrite) {
                return;
            }

            $originalFinalisedAt = $inspection->getOriginal('finalised_at');

            if ($originalFinalisedAt === null) {
                return;
            }

            $dirtyContentFields = array_intersect(
                array_keys($inspection->getDirty()),
                self::CONTENT_FIELDS
            );

            if ($dirtyContentFields !== []) {
                throw new RuntimeException(
                    'Cannot modify report content on a finalised inspection ('
                    .implode(', ', $dirtyContentFields)
                    .'). Use Inspection::amend() instead.'
                );
            }
        });
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(Inspector::class);
    }

    public function timberLot(): BelongsTo
    {
        return $this->belongsTo(TimberLot::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(InspectionAmendment::class);
    }

    /**
     * The formal amended-report workflow: records a before/after diff as an
     * InspectionAmendment, then applies the change to this report. The only
     * sanctioned way to alter report content after finalisation.
     *
     * @param  array<string, mixed>  $changes  field => new value
     */
    public function amend(array $changes, User $amendedBy, string $reason): InspectionAmendment
    {
        $diff = [];

        foreach ($changes as $field => $newValue) {
            $diff[$field] = [
                'before' => $this->getAttribute($field),
                'after' => $newValue,
            ];
        }

        $amendment = $this->amendments()->create([
            'amended_by' => $amendedBy->id,
            'reason' => $reason,
            'changes' => $diff,
        ]);

        $this->allowGuardedWrite = true;
        $this->fill($changes);
        $this->save();
        $this->allowGuardedWrite = false;

        return $amendment;
    }

    /**
     * Finalises the report: computes and stores a digital signature hash
     * over the canonical report content, sets finalised_at, and pushes the
     * result onto the related TimberLot's inspection_status.
     */
    public function finalise(): static
    {
        $signature = hash('sha256', json_encode(
            $this->only(self::CONTENT_FIELDS),
            JSON_THROW_ON_ERROR
        ));

        $this->allowGuardedWrite = true;
        $this->digital_signature = $signature;
        $this->finalised_at = now();
        $this->save();
        $this->allowGuardedWrite = false;

        if ($this->result && $this->timberLot) {
            $this->timberLot->update(['inspection_status' => match ($this->result) {
                'pass' => 'passed',
                'fail' => 'failed',
                'conditional' => 'conditional',
                default => $this->timberLot->inspection_status,
            }]);
        }

        return $this;
    }
}
