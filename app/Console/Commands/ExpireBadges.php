<?php

namespace App\Console\Commands;

use App\Jobs\ExpireBadgesJob;
use Illuminate\Console\Command;

/**
 * Daily lifecycle sweep: dispatches ExpireBadgesJob to flip active badges
 * past their valid_until to expired on the queue.
 */
class ExpireBadges extends Command
{
    protected $signature = 'compliance:expire-badges';

    protected $description = 'Expire verification badges past their valid_until date';

    public function handle(): int
    {
        ExpireBadgesJob::dispatch();

        $this->info('ExpireBadgesJob dispatched.');

        return self::SUCCESS;
    }
}
