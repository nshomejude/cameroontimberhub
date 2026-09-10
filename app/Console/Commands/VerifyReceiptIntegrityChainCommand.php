<?php

namespace App\Console\Commands;

use App\Models\Receipt;
use Illuminate\Console\Command;

/**
 * Walks the receipt integrity hash chain (production-readiness plan Task B3)
 * in issue order (issued_at, then id) and reports the first receipt whose
 * stored hash no longer matches what its own issued facts (plus the previous
 * receipt's hash) recompute to -- proof that some row was altered, deleted or
 * reordered after being written, bypassing Eloquent (a raw UPDATE, a restored
 * backup missing later rows, etc.).
 *
 * Mirrors activitylog:verify-chain.
 */
class VerifyReceiptIntegrityChainCommand extends Command
{
    protected $signature = 'receipts:verify-chain';

    protected $description = 'Verify the receipt integrity hash chain has not been tampered with';

    public function handle(): int
    {
        $previousHash = null;

        foreach (Receipt::query()->orderBy('issued_at')->orderBy('id')->cursor() as $receipt) {
            if ($receipt->hash === null) {
                // Rows written before the backfill ran -- not a tamper signal.
                // Reset so the chain resumes cleanly from the first chained row.
                $previousHash = null;

                continue;
            }

            if ($receipt->prev_hash !== $previousHash) {
                $this->error("Chain broken at receipt {$receipt->receipt_number} (#{$receipt->id}): expected prev_hash [{$previousHash}], found [{$receipt->prev_hash}].");

                return self::FAILURE;
            }

            if (! $receipt->verifiesIntegrity()) {
                $this->error("Chain broken at receipt {$receipt->receipt_number} (#{$receipt->id}): stored hash does not match its own recomputed issued facts. This receipt's data was altered after it was issued.");

                return self::FAILURE;
            }

            $previousHash = $receipt->hash;
        }

        $this->info('Chain intact.');

        return self::SUCCESS;
    }
}
