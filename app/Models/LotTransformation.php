<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Mass-balance / transformation record (implementation blueprint §11).
 * Distinct from the lot_events activity log: this is not "something
 * happened to a lot", it's "these input lots, in these quantities, became
 * these output lots, in these quantities, with this much loss".
 */
class LotTransformation extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'input_volume_m3' => 'decimal:3',
            'output_volume_m3' => 'decimal:3',
            'loss_volume_m3' => 'decimal:3',
            'transformation_ratio' => 'decimal:4',
            'processed_at' => 'datetime',
        ];
    }

    public function processorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'processor_company_id');
    }

    public function inputLots(): BelongsToMany
    {
        return $this->belongsToMany(TimberLot::class, 'lot_transformation_inputs')
            ->withPivot('quantity_m3')
            ->withTimestamps();
    }

    public function outputLots(): BelongsToMany
    {
        return $this->belongsToMany(TimberLot::class, 'lot_transformation_outputs')
            ->withPivot('quantity_m3')
            ->withTimestamps();
    }

    /**
     * Record a transformation from a set of input lots to a set of output
     * lots, computing totals from the line items rather than trusting the
     * caller to supply correct aggregates.
     *
     * $inputs / $outputs: array<array{lot: TimberLot|int, quantity: float|string}>
     */
    public static function recordFor(
        int $processorCompanyId,
        string $transformationType,
        array $inputs,
        array $outputs,
        ?\DateTimeInterface $processedAt = null,
        ?string $notes = null,
    ): self {
        return DB::transaction(function () use ($processorCompanyId, $transformationType, $inputs, $outputs, $processedAt, $notes) {
            $inputTotal = collect($inputs)->sum(fn (array $row) => (float) $row['quantity']);
            $outputTotal = collect($outputs)->sum(fn (array $row) => (float) $row['quantity']);
            $loss = $inputTotal - $outputTotal;
            $ratio = $inputTotal > 0 ? round($outputTotal / $inputTotal, 4) : null;

            $transformation = self::create([
                'processor_company_id' => $processorCompanyId,
                'transformation_type' => $transformationType,
                'input_volume_m3' => $inputTotal,
                'output_volume_m3' => $outputTotal,
                'loss_volume_m3' => $loss,
                'transformation_ratio' => $ratio,
                'processed_at' => $processedAt ?? now(),
                'notes' => $notes,
            ]);

            foreach ($inputs as $row) {
                $lotId = $row['lot'] instanceof TimberLot ? $row['lot']->id : $row['lot'];
                $transformation->inputLots()->attach($lotId, ['quantity_m3' => $row['quantity']]);
            }

            foreach ($outputs as $row) {
                $lotId = $row['lot'] instanceof TimberLot ? $row['lot']->id : $row['lot'];
                $transformation->outputLots()->attach($lotId, ['quantity_m3' => $row['quantity']]);
            }

            return $transformation;
        });
    }
}
