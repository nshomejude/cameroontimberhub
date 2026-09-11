<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Notifications\SubscriptionLapsedToFree;
use App\Notifications\SubscriptionPastDue;
use App\Notifications\SubscriptionRenewalReminder;
use App\Notifications\TrialEndedUnpaid;
use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Pull-model subscription renewals (billing engine M6, §7.5).
 *
 * There is no stored payment mandate for MTN MoMo / Orange Money, so renewals
 * are never auto-charged. This daily job walks every PAID subscription
 * (`price_amount > 0`; Free subs are non-expiring and skipped) and applies the
 * state machine:
 *
 *   Trialing, trial_ends_at past, unpaid   → Expired + lapse to segment Free  ("your trial ended")
 *   Active,   renews_at − 7d ≤ now < renews_at, not yet reminded
 *                                          → renewal reminder + set renewal_reminded_at
 *   Active,   renews_at past               → PastDue, grace_until = renews_at + 7d  ("payment past due")
 *   PastDue,  grace_until past             → Expired + lapse to segment Free  ("your subscription lapsed")
 *
 * Idempotent and safe to run repeatedly: the reminder is guarded by
 * `renewal_reminded_at`; every transition moves the row out of the branch that
 * produced it. Non-zero exit only on an actual error.
 *
 * NOT handled here (deliberate, separate deferred sub-tasks of M6):
 *   - card-on-file / auto-charge for the USD (Stripe/PayPal) rails
 *   - proration on mid-term upgrade/downgrade
 */
class ProcessSubscriptionRenewals extends Command
{
    protected $signature = 'subscriptions:process-renewals';

    protected $description = 'Pull-model subscription renewals: reminders, grace, lapse-to-Free (billing engine M6)';

    private const REMINDER_LEAD_DAYS = 7;

    private const GRACE_DAYS = 7;

    public function handle(SubscriptionService $subscriptions): int
    {
        $now = now();
        $reminded = 0;
        $pastDue = 0;
        $lapsed = 0;
        $hadError = false;

        $rows = Subscription::query()
            ->where('price_amount', '>', 0)
            ->whereIn('status', [
                SubscriptionStatus::Active->value,
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->with(['company.users', 'plan'])
            ->get();

        foreach ($rows as $sub) {
            try {
                // Defensive: never touch a Free-plan subscription even if one
                // somehow carries a non-zero snapshot price.
                if ($sub->plan?->isFree()) {
                    continue;
                }

                $company = $sub->company;
                if ($company === null) {
                    continue;
                }

                if ($sub->status === SubscriptionStatus::Trialing) {
                    if ($sub->trial_ends_at !== null && $sub->trial_ends_at->lte($now)) {
                        $previousPlanId = $sub->plan_id;
                        $sub->update(['status' => SubscriptionStatus::Expired, 'ends_at' => $now]);
                        $subscriptions->lapseToFree($company, $previousPlanId);
                        $this->notifyCompany($company, new TrialEndedUnpaid($sub));
                        $lapsed++;
                    }

                    continue;
                }

                if ($sub->status === SubscriptionStatus::PastDue) {
                    if ($sub->grace_until !== null && $sub->grace_until->lte($now)) {
                        $previousPlanId = $sub->plan_id;
                        $sub->update(['status' => SubscriptionStatus::Expired, 'ends_at' => $now]);
                        $free = $subscriptions->lapseToFree($company, $previousPlanId);
                        $this->notifyCompany($company, new SubscriptionLapsedToFree($sub, $free?->plan));
                        $lapsed++;
                    }

                    continue;
                }

                // ---- Active ----
                if ($sub->renews_at === null) {
                    continue;
                }

                if ($sub->renews_at->lte($now)) {
                    $sub->update([
                        'status' => SubscriptionStatus::PastDue,
                        'grace_until' => $sub->renews_at->copy()->addDays(self::GRACE_DAYS),
                    ]);
                    $this->notifyCompany($company, new SubscriptionPastDue($sub->fresh()));
                    activity('subscription')->performedOn($company)->event('past_due')
                        ->withProperties(['plan' => $sub->plan?->slug])->log('Subscription entered past-due grace');
                    $pastDue++;

                    continue;
                }

                if ($sub->renewal_reminded_at === null
                    && $now->gte($sub->renews_at->copy()->subDays(self::REMINDER_LEAD_DAYS))) {
                    $this->notifyCompany($company, new SubscriptionRenewalReminder($sub));
                    $sub->update(['renewal_reminded_at' => $now]);
                    $reminded++;
                }
            } catch (Throwable $e) {
                $hadError = true;
                Log::channel('errors')->error('subscriptions:process-renewals failed for a subscription row.', [
                    'subscription_id' => $sub->getKey(),
                    'exception' => $e->getMessage(),
                    'exception_class' => $e::class,
                ]);
            }
        }

        $this->info("Subscription renewals: {$reminded} reminded, {$pastDue} past-due, {$lapsed} lapsed.");

        return $hadError ? self::FAILURE : self::SUCCESS;
    }

    private function notifyCompany(\App\Models\Company $company, object $notification): void
    {
        $recipients = $company->users;

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, $notification);
        }
    }
}
