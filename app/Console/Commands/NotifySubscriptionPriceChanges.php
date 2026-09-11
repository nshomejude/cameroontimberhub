<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 30-day advance notice of scheduled subscription price changes
 * (billing engine M6, §7.5 / spec §23).
 *
 * STUB until M9 (price governance / `plan_prices` versioning) lands. Price
 * versioning with `effective_from` / `effective_until` is what makes a
 * "scheduled" price change a first-class, queryable thing; without that table
 * there is nothing to notify about, so this exits cleanly. Once `plan_prices`
 * exists, this command should: find rows whose `effective_from` is ~30 days
 * out, find the companies on an active paid subscription for that plan at the
 * old snapshot price, and send each a price-change notice.
 */
class NotifySubscriptionPriceChanges extends Command
{
    protected $signature = 'subscriptions:notify-price-changes';

    protected $description = 'Send 30-day advance notice of scheduled subscription price changes (billing engine M6; activates with M9)';

    public function handle(): int
    {
        if (! Schema::hasTable('plan_prices')) {
            $this->info('No scheduled price changes — price versioning (billing engine M9) is not present yet.');

            return self::SUCCESS;
        }

        $this->info('No scheduled price changes.');

        return self::SUCCESS;
    }
}
