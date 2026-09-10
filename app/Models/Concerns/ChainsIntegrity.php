<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tamper-evident hash chain for issued records (production-readiness plan
 * Task B3). Modelled on App\Models\ChainedActivity: on `creating`,
 * `prev_hash` is the immediately preceding row's `hash` (global issue
 * order, locked) and `hash` = sha256(prev_hash . canonical payload).
 *
 * The chain covers only the record's *issued facts* -- the columns named by
 * `integrityPayloadColumns()`. Mutable operational state (verification
 * telemetry, void status) is deliberately excluded, so recording a check or
 * voiding a receipt never needs to re-chain. `updating` is guarded so the
 * payload columns can never change after the row is written.
 */
trait ChainsIntegrity
{
    public static function bootChainsIntegrity(): void
    {
        static::creating(function ($model) {
            // A row that already carries a hash (the backfill command writes
            // rows directly, tests may pin one) is taken as-is.
            if ($model->hash === null) {
                $model->assignIntegrityChain();
            }
        });

        static::updating(function ($model) {
            foreach (array_merge($model->integrityPayloadColumns(), ['hash', 'prev_hash']) as $column) {
                if ($model->isDirty($column)) {
                    throw new RuntimeException(
                        class_basename($model)." integrity column [{$column}] is immutable after the record is issued."
                    );
                }
            }
        });
    }

    /**
     * Columns whose values the hash attests to. Frozen at creation.
     *
     * @return list<string>
     */
    abstract public function integrityPayloadColumns(): array;

    /** Deterministic, order-fixed serialisation of the payload columns. */
    public function canonicalIntegrityPayload(): string
    {
        $parts = [];

        foreach ($this->integrityPayloadColumns() as $column) {
            $value = $this->getAttribute($column);

            if ($value instanceof \DateTimeInterface) {
                // Wall-clock form as persisted -- NOT tz-converted. A
                // timestamp round-tripped through the database comes back in
                // the connection timezone, and re-converting it (->utc(),
                // toIso8601String()) is what shifts the string and breaks the
                // recompute. The stored wall-clock is the stable anchor.
                $value = $value->format('Y-m-d H:i:s');
            } elseif ($value instanceof \BackedEnum) {
                $value = $value->value;
            }

            $parts[] = $column.'='.(string) $value;
        }

        return implode('|', $parts);
    }

    /** Compute and assign `prev_hash` + `hash` for a row being created. */
    public function assignIntegrityChain(): void
    {
        DB::transaction(function () {
            $previous = static::query()
                ->whereNotNull('hash')
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $this->prev_hash = $previous?->hash;
            $this->hash = $this->computeIntegrityHash();
        });
    }

    public function computeIntegrityHash(): string
    {
        return hash('sha256', ($this->prev_hash ?? '').$this->canonicalIntegrityPayload());
    }

    /** True when this row's stored hash matches a fresh recompute of it. */
    public function verifiesIntegrity(): bool
    {
        return $this->hash !== null && hash_equals($this->hash, $this->computeIntegrityHash());
    }
}
