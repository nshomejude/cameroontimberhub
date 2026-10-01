<?php

namespace App\Console\Commands;

use App\Services\Commission\CommissionCollectionService;
use Illuminate\Console\Command;

/**
 * Daily commission-statement upkeep: flags statements past their due date as
 * `overdue` (notifying the supplier once) and sends the one-off "due soon"
 * reminder. Idempotent — see CommissionCollectionService::processDueDates().
 */
class ProcessCommissionStatements extends Command
{
    protected $signature = 'commission:process-statements';

    protected $description = 'Send commission statement due reminders and flag overdue statements';

    public function handle(CommissionCollectionService $collection): int
    {
        $result = $collection->processDueDates();

        $this->info("{$result['overdue']} statement(s) newly overdue, {$result['reminded']} due-soon reminder(s) sent.");

        return self::SUCCESS;
    }
}
