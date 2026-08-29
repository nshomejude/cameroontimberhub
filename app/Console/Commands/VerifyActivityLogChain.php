<?php

namespace App\Console\Commands;

use App\Models\ChainedActivity;
use Illuminate\Console\Command;

/**
 * Walks the activity log's hash chain (gap-plan 0.5) in insertion order and
 * reports the first row whose stored hash no longer matches what its own
 * fields (plus the previous row's hash) recompute to -- proof that some row
 * was altered after being written, bypassing Eloquent (a raw UPDATE, a
 * restored backup missing later rows, etc.).
 */
class VerifyActivityLogChain extends Command
{
    protected $signature = 'activitylog:verify-chain';

    protected $description = 'Verify the activity log hash chain has not been tampered with';

    public function handle(): int
    {
        $previousHash = null;

        foreach (ChainedActivity::orderBy('id')->cursor() as $activity) {
            if ($activity->hash === null) {
                // Rows created before this chain existed -- not a tamper
                // signal, just pre-chain history. Skip and reset the
                // expected previous hash so the chain resumes cleanly from
                // the first chained row.
                $previousHash = null;

                continue;
            }

            if ($activity->prev_hash !== $previousHash) {
                $this->error("Chain broken at activity #{$activity->id}: expected prev_hash [{$previousHash}], found [{$activity->prev_hash}].");

                return self::FAILURE;
            }

            $expectedHash = hash('sha256', json_encode([
                'log_name' => $activity->log_name,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id,
                'causer_type' => $activity->causer_type,
                'causer_id' => $activity->causer_id,
                'properties' => $activity->properties?->toArray(),
                'prev_hash' => $activity->prev_hash,
            ], JSON_THROW_ON_ERROR));

            if ($expectedHash !== $activity->hash) {
                $this->error("Chain broken at activity #{$activity->id}: stored hash does not match its own recomputed fields. This row's data was altered after being logged.");

                return self::FAILURE;
            }

            $previousHash = $activity->hash;
        }

        $this->info('Chain intact.');

        return self::SUCCESS;
    }
}
