<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Allocates the platform's billing-document numbers (billing engine M4):
 *
 *   invoice     → CTH-INV-YYYY-NNNNN
 *   credit note → CTH-CN-YYYY-NNNNN
 *
 * `NNNNN` is a per-series, per-year, gap-free monotonic sequence. The single
 * counter row in `billing_document_sequences` for (series, year) is taken
 * with `lockForUpdate` inside a transaction, mirroring the locked-sequence
 * discipline of App\Support\CarbonProjectIdentifier / ProductIdentifier.
 */
class InvoiceNumberGenerator
{
    public function invoice(): string
    {
        return $this->next('INV');
    }

    public function creditNote(): string
    {
        return $this->next('CN');
    }

    private function next(string $series): string
    {
        $year = (int) now()->year;

        return DB::transaction(function () use ($series, $year): string {
            $current = (int) (DB::table('billing_document_sequences')
                ->where('series', $series)
                ->where('year', $year)
                ->lockForUpdate()
                ->value('last_value') ?? 0);

            $next = $current + 1;

            DB::table('billing_document_sequences')->upsert(
                [['series' => $series, 'year' => $year, 'last_value' => $next, 'created_at' => now(), 'updated_at' => now()]],
                ['series', 'year'],
                ['last_value' => $next, 'updated_at' => now()],
            );

            return sprintf('CTH-%s-%d-%05d', $series, $year, $next);
        });
    }
}
