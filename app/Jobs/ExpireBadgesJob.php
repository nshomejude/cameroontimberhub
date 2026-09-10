<?php

namespace App\Jobs;

use App\Enums\BadgeStatus;
use App\Models\VerificationBadge;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ExpireBadgesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function failed(?\Throwable $e): void
    {
        Log::channel('errors')->error('ExpireBadgesJob failed permanently — expired verification badges were not swept.', [
            'job' => self::class,
            'exception' => $e?->getMessage(),
            'exception_class' => $e ? $e::class : null,
        ]);
    }

    public function handle(): void
    {
        VerificationBadge::query()
            ->where('status', BadgeStatus::Active->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', today())
            ->update(['status' => BadgeStatus::Expired->value]);
    }
}
