<?php

namespace App\Console\Commands;

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Walks the invoice and credit-note integrity hash chains (billing engine
 * M4) in issue order (issued_at, then id) and reports the first row whose
 * stored hash no longer matches a fresh recompute — proof a historical
 * billing document was altered, deleted or reordered after being issued.
 *
 * Mirrors receipts:verify-chain. Both chains are independent; a break in
 * either fails the command.
 */
class VerifyInvoiceIntegrityChainCommand extends Command
{
    protected $signature = 'invoices:verify-chain';

    protected $description = 'Verify the invoice and credit-note integrity hash chains have not been tampered with';

    public function handle(): int
    {
        $ok = $this->walk(Invoice::query()->orderBy('issued_at')->orderBy('id')->cursor(), 'invoice', 'invoice_number')
            & $this->walk(CreditNote::query()->orderBy('issued_at')->orderBy('id')->cursor(), 'credit note', 'credit_note_number');

        if (! $ok) {
            return self::FAILURE;
        }

        $this->info('Chain intact.');

        return self::SUCCESS;
    }

    /** @param iterable<Model> $rows */
    private function walk(iterable $rows, string $label, string $numberColumn): bool
    {
        $previousHash = null;

        foreach ($rows as $row) {
            if ($row->hash === null) {
                $previousHash = null;

                continue;
            }

            $number = $row->{$numberColumn};

            if ($row->prev_hash !== $previousHash) {
                $this->error("Chain broken at {$label} {$number} (#{$row->id}): expected prev_hash [{$previousHash}], found [{$row->prev_hash}].");

                return false;
            }

            if (! $row->verifiesIntegrity()) {
                $this->error("Chain broken at {$label} {$number} (#{$row->id}): stored hash does not match its own recomputed issued facts. This record was altered after it was issued.");

                return false;
            }

            $previousHash = $row->hash;
        }

        return true;
    }
}
