<?php

namespace App\Console\Commands;

use App\Models\Receipt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills the receipt integrity hash chain (production-readiness plan Task
 * B3) for receipts issued before the chain existed.
 *
 * Walks receipts in issue order (issued_at, then id) and recomputes each
 * row's `prev_hash` + `hash` from its frozen issued facts. Writes go
 * straight to the table (the model guards these columns against update), and
 * only when a value actually changes -- so re-running the command over an
 * already-chained table is a no-op and produces identical hashes.
 */
class BackfillReceiptIntegrityChainCommand extends Command
{
    protected $signature = 'receipts:backfill-integrity-chain';

    protected $description = 'Compute the receipt integrity hash chain for receipts issued before the chain existed';

    public function handle(): int
    {
        $previousHash = null;
        $updated = 0;

        foreach (Receipt::query()->orderBy('issued_at')->orderBy('id')->cursor() as $receipt) {
            $receipt->prev_hash = $previousHash;
            $hash = $receipt->computeIntegrityHash();

            if ($receipt->getOriginal('hash') !== $hash || $receipt->getOriginal('prev_hash') !== $previousHash) {
                DB::table('receipts')->where('id', $receipt->id)->update([
                    'prev_hash' => $previousHash,
                    'hash' => $hash,
                ]);
                $updated++;
            }

            $previousHash = $hash;
        }

        $this->info("Receipt integrity chain backfilled. {$updated} row(s) updated.");

        return self::SUCCESS;
    }
}
