<?php

namespace App\Console\Commands;

use App\Enums\LotEventType;
use App\Models\LotEvent;
use App\Models\TimberLot;
use Illuminate\Console\Command;

/**
 * Walks each lot's Traceability Event Ledger hash chain (blueprint §10) in
 * insertion order and reports the first row whose stored hash no longer
 * matches what its own fields (plus the previous row's hash) recompute to
 * -- proof that some row was altered after being written, bypassing
 * Eloquent. Mirrors activitylog:verify-chain's structure, but scoped per
 * lot since lot_events chains per-lot rather than globally.
 */
class VerifyLotEventChainCommand extends Command
{
    protected $signature = 'lot-events:verify-chain {lot_number?}';

    protected $description = 'Verify the traceability event ledger hash chain for a lot (or all lots) has not been tampered with';

    public function handle(): int
    {
        $lotNumber = $this->argument('lot_number');

        $lots = $lotNumber
            ? TimberLot::query()->where('lot_number', $lotNumber)->get()
            : TimberLot::query()->get();

        if ($lots->isEmpty()) {
            $this->error($lotNumber ? "No timber lot found with lot_number [{$lotNumber}]." : 'No timber lots found.');

            return self::FAILURE;
        }

        $anyBroken = false;

        foreach ($lots as $lot) {
            if (! $this->verifyLot($lot)) {
                $anyBroken = true;
            }
        }

        if ($anyBroken) {
            return self::FAILURE;
        }

        $this->info('Chain intact.');

        return self::SUCCESS;
    }

    private function verifyLot(TimberLot $lot): bool
    {
        $previousHash = null;

        foreach (LotEvent::query()->where('timber_lot_id', $lot->id)->orderBy('id')->cursor() as $event) {
            if ($event->previous_event_hash !== $previousHash) {
                $this->error("Chain broken at lot event #{$event->id} (lot {$lot->lot_number}): expected previous_event_hash [{$previousHash}], found [{$event->previous_event_hash}].");

                return false;
            }

            $expectedHash = hash('sha256', json_encode([
                'timber_lot_id' => $event->timber_lot_id,
                'event_type' => $event->event_type instanceof LotEventType ? $event->event_type->value : $event->event_type,
                'actor_id' => $event->actor_id,
                'location' => $event->location,
                'quantity_before' => $event->quantity_before,
                'quantity_after' => $event->quantity_after,
                'documents' => $event->documents,
                'evidence' => $event->evidence,
                'notes' => $event->notes,
                'previous_event_hash' => $event->previous_event_hash,
            ], JSON_THROW_ON_ERROR));

            if ($expectedHash !== $event->event_hash) {
                $this->error("Chain broken at lot event #{$event->id} (lot {$lot->lot_number}): stored hash does not match its own recomputed fields. This row's data was altered after being logged.");

                return false;
            }

            $previousHash = $event->event_hash;
        }

        return true;
    }
}
