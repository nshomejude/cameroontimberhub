<?php

namespace App\Console\Commands;

use App\Jobs\SendDocumentExpiryReminderJob;
use Illuminate\Console\Command;

/**
 * Daily expiry reminders: dispatches SendDocumentExpiryReminderJob to the
 * queue, which sends reminders at 90/60/30 days and on expiry (idempotent).
 */
class RemindExpiringDocuments extends Command
{
    protected $signature = 'compliance:remind-expiring';

    protected $description = 'Send expiry reminders for approved compliance documents';

    public function handle(): int
    {
        SendDocumentExpiryReminderJob::dispatch();

        $this->info('SendDocumentExpiryReminderJob dispatched.');

        return self::SUCCESS;
    }
}
