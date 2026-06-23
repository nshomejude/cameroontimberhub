<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\CompanyDocument;
use App\Models\DocumentReminderLog;
use App\Notifications\DocumentExpiring;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Notification;

class SendDocumentExpiryReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(): void
    {
        CompanyDocument::query()
            ->whereNotNull('expiry_date')
            ->where('status', DocumentStatus::Approved->value)
            ->with('company.users')
            ->chunkById(200, function ($documents): void {
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
                        continue;
                    }

                    Notification::send($document->company->users, new DocumentExpiring($document, $threshold));
                }
            });
    }

    /** @return '90'|'60'|'30'|'expired'|null */
    private function thresholdBucket(CarbonInterface $expiry): ?string
    {
        $expiry = $expiry->copy()->startOfDay();
        $today  = today();

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
