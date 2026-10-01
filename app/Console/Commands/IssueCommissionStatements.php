<?php

namespace App\Console\Commands;

use App\Models\CommissionPaymentSetting;
use App\Services\Commission\CommissionStatementIssuer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Issues the monthly marketplace-commission statements (owner decision
 * 2026-10-01: collected by manual MoMo / bank deposit). Scheduled on the 1st
 * of every month for the PREVIOUS month; `--month=YYYY-MM` re-runs (or
 * back-fills) a given month. Idempotent per company + period + currency —
 * see App\Services\Commission\CommissionStatementIssuer.
 */
class IssueCommissionStatements extends Command
{
    protected $signature = 'commission:issue-statements {--month= : The month to bill, YYYY-MM (default: the previous month)}';

    protected $description = 'Issue monthly marketplace-commission statements to suppliers';

    public function handle(CommissionStatementIssuer $issuer): int
    {
        $option = $this->option('month');

        if (filled($option)) {
            if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $option)) {
                $this->error('--month must be YYYY-MM, e.g. 2026-09.');

                return self::INVALID;
            }

            $month = CarbonImmutable::createFromFormat('!Y-m', (string) $option);

            if ($month->startOfMonth()->greaterThan(now()->startOfMonth())) {
                $this->error('Cannot issue statements for a future month.');

                return self::INVALID;
            }
        } else {
            $month = CarbonImmutable::now()->startOfMonth()->subMonth();
        }

        if (! CommissionPaymentSetting::current()->isConfigured()) {
            // Statements still go out (the debt is real), but finance must
            // fill in where to pay — RUNBOOK → Commission collection.
            $this->warn('No commission payment instructions are configured (/admin → Commission payment instructions). Statements will show no payment details.');
            Log::channel('errors')->warning('commission:issue-statements ran without commission payment instructions configured.');
        }

        $result = $issuer->issueForMonth($month);

        foreach ($result['issued'] as $statement) {
            $this->line("  {$statement->statement_number}  company #{$statement->company_id}  {$statement->money()}");
        }

        $this->info(sprintf(
            'Period %s: %d statement(s) issued, %d already existed, %d skipped (zero or credit balance).',
            $result['period'], count($result['issued']), $result['existing'], $result['skipped_non_positive'],
        ));

        return self::SUCCESS;
    }
}
