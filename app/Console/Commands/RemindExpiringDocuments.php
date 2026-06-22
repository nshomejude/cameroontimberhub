<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\CompanyDocument;
use App\Models\DocumentReminderLog;
use App\Notifications\DocumentExpiring;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Notification;

/**
 * Daily expiry reminders for approved documents at 90/60/30 days and on expiry.
 * Idempotent: each (document, threshold) fires at most once via the unique
 * constraint on document_reminder_logs.
 */
class RemindExpiringDocuments extends Command
{
    protected $signature = 'compliance:remind-expiring';

    protected $description = 'Send expiry reminders for approved compliance documents';

    public function handle(): int
    {
        $sent = 0;

        CompanyDocument::query()
            ->whereNotNull('expiry_date')
            ->where('status', DocumentStatus::Approved->value)
            ->with('company.users')
            ->chunkById(200, function ($documents) use (&$sent): void {
                foreach ($documents as $document) {
                    $threshold = $this->thresholdBucket($document->expiry_date);
                    if ($threshold === null) {
                        continue;
                    }

                    $log = DocumentReminderLog::firstOrCreate(
                        ['company_document_id' => $document->getKey(), 'threshold' => $threshold],
                        ['sent_at' => now()],
                    );

                    if (! $log->wasRecentlyCreated) {
                        continue; // already sent this threshold (idempotent)
                    }

                    Notification::send($document->company->users, new DocumentExpiring($document, $threshold));
                    $sent++;
                }
            });

        $this->info("Sent {$sent} expiry reminder(s).");

        return self::SUCCESS;
    }

    /** @return '90'|'60'|'30'|'expired'|null */
    protected function thresholdBucket(CarbonInterface $expiry): ?string
    {
        $expiry = $expiry->copy()->startOfDay();
        $today = today();

        if ($expiry->lessThan($today)) {
            return 'expired';
        }

        $days = (int) $today->diffInDays($expiry);

        foreach ([30, 60, 90] as $threshold) {
            if ($days <= $threshold) {
                return (string) $threshold;
            }
        }

        return null;
    }
}
