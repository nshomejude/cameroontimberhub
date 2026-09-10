<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\CompanyDocument;
use App\Models\Document;
use App\Models\DocumentReminderLog;
use App\Notifications\DocumentExpiring;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendDocumentExpiryReminderJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('errors')->error('SendDocumentExpiryReminderJob failed permanently — document expiry reminders did not go out.', [
            'job' => self::class,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }

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
                        ['document_owner_type' => CompanyDocument::class, 'document_owner_id' => $document->getKey(), 'threshold' => $threshold],
                        ['sent_at' => now()],
                    );

                    if (! $log->wasRecentlyCreated) {
                        continue;
                    }

                    Notification::send($document->company->users, new DocumentExpiring($document, $threshold));
                }
            });

        // Document-owned entities (Species today) have no established
        // "owner users to notify" path yet -- no consumer needs expiry
        // emails for them yet, unlike CompanyDocument's company->users.
        // Still log the reminder threshold polymorphically so the ledger
        // is complete and a future notification path has data to build on.
        Document::query()
            ->whereNotNull('expires_at')
            ->chunkById(200, function ($documents): void {
                foreach ($documents as $document) {
                    $threshold = $this->thresholdBucket($document->expires_at);
                    if ($threshold === null) {
                        continue;
                    }

                    DocumentReminderLog::firstOrCreate(
                        ['document_owner_type' => Document::class, 'document_owner_id' => $document->getKey(), 'threshold' => $threshold],
                        ['sent_at' => now()],
                    );
                }
            });
    }

    /** @return '90'|'60'|'30'|'expired'|null */
    private function thresholdBucket(CarbonInterface $expiry): ?string
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
