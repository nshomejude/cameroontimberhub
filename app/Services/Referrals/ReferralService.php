<?php

namespace App\Services\Referrals;

use App\Enums\PaymentStatus;
use App\Enums\ReferralEarningStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\ReferralEarning;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Notifications\ReferralCommissionEarnedNotification;
use App\Notifications\ReferralSignedUpNotification;
use App\Services\Payments\PaymentAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The referral programme's single write path.
 *
 * - Codes: `codeFor()` lazily generates a unique CTH-XXXXXX code for a user
 *   or company (backfilled on first read; `referrals:backfill-codes` does all).
 * - Sign-up: `resolveReferrer()` / `selfReferralReason()` back the
 *   `referral_code` registration rule; `attachReferrer()` stores it.
 * - Commission: `awardForPayment()` runs off the billing engine's
 *   PaymentCompleted event and creates ONE earning, `rate_percent` of the
 *   referred company's FIRST completed subscription payment, computed on the
 *   price excluding tax and passed-through provider fees (`commissionBase()`).
 *   Renewals (any later completed plan payment) earn nothing while
 *   `one_time` is on. Paying it out: ReferralPayoutService.
 */
class ReferralService
{
    /** Public webmail domains — sharing one is NOT evidence of self-referral. */
    public const FREE_MAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.fr', 'ymail.com', 'hotmail.com', 'hotmail.fr',
        'outlook.com', 'outlook.fr', 'live.com', 'live.fr', 'msn.com', 'icloud.com', 'me.com', 'mac.com',
        'aol.com', 'gmx.com', 'gmx.de', 'gmx.fr', 'mail.com', 'proton.me', 'protonmail.com', 'zoho.com',
        'yandex.com', 'yandex.ru', 'orange.fr', 'free.fr', 'laposte.net', 'wanadoo.fr', 'sfr.fr', 'qq.com',
        '163.com', '126.com',
    ];

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function settings(): ReferralSetting
    {
        return ReferralSetting::current();
    }

    public function codeFor(User|Company $owner): string
    {
        if (filled($owner->referral_code)) {
            return $owner->referral_code;
        }

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = self::generateCode();

            if (User::where('referral_code', $code)->exists() || Company::where('referral_code', $code)->exists()) {
                continue;
            }

            try {
                $owner->forceFill(['referral_code' => $code])->saveQuietly();

                return $code;
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new \RuntimeException('Could not generate a unique referral code.');
    }

    public static function generateCode(): string
    {
        $suffix = '';
        for ($i = 0; $i < 6; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return 'CTH-'.$suffix;
    }

    public static function normalise(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code === '' ? null : $code;
    }

    /**
     * The user credited for a code: the user who owns it, or — for a company
     * code — the company's creator (else its first member).
     *
     * @return array{user: User, company: ?Company}|null
     */
    public function resolveReferrer(?string $code): ?array
    {
        $code = self::normalise($code);
        if ($code === null) {
            return null;
        }

        if ($user = User::where('referral_code', $code)->first()) {
            return ['user' => $user, 'company' => $user->companies()->orderBy('companies.id')->first()];
        }

        if ($company = Company::where('referral_code', $code)->first()) {
            $user = $company->created_by ? User::find($company->created_by) : null;
            $user ??= $company->users()->orderBy('users.id')->first();

            return $user ? ['user' => $user, 'company' => $company] : null;
        }

        return null;
    }

    /**
     * Why this registration would be a self-referral, or null if it is fine.
     * Same user, same company, or the same NON-free-mail email domain.
     *
     * @param  array{user: User, company: ?Company}  $referrer
     */
    public function selfReferralReason(array $referrer, string $email, ?User $newUser = null, ?Company $newCompany = null): ?string
    {
        if ($newUser && $newUser->is($referrer['user'])) {
            return 'same_user';
        }

        if (strcasecmp($referrer['user']->email, $email) === 0) {
            return 'same_user';
        }

        if ($newCompany && $referrer['company'] && $newCompany->is($referrer['company'])) {
            return 'same_company';
        }

        $domain = strtolower((string) Str::after($email, '@'));
        $referrerDomain = strtolower((string) Str::after($referrer['user']->email, '@'));

        if ($domain !== '' && $domain === $referrerDomain && ! in_array($domain, self::FREE_MAIL_DOMAINS, true)) {
            return 'same_domain';
        }

        return null;
    }

    /** Record the referrer on a freshly registered user (and its company). */
    public function attachReferrer(User $user, ?Company $company, ?string $code): void
    {
        $referrer = $this->resolveReferrer($code);

        if ($referrer === null || $this->selfReferralReason($referrer, $user->email, $user, $company) !== null) {
            return;
        }

        $user->forceFill(['referred_by_user_id' => $referrer['user']->id, 'referred_at' => now()])->saveQuietly();

        $company?->forceFill([
            'referred_by_user_id' => $referrer['user']->id,
            'referred_by_company_id' => $referrer['company']?->id,
        ])->saveQuietly();

        $referrerUser = $referrer['user'];
        DB::afterCommit(fn () => $referrerUser->notify(new ReferralSignedUpNotification($user)));
    }

    /**
     * Create the referral commission for a completed subscription payment,
     * if the programme rules allow. Idempotent by payment_id.
     */
    public function awardForPayment(Payment $payment): ?ReferralEarning
    {
        if ($payment->plan_id === null || $payment->status !== PaymentStatus::Completed || (float) $payment->amount <= 0) {
            return null;
        }

        $settings = $this->settings();
        if (! $settings->enabled || $settings->basis !== 'subscription') {
            return null;
        }

        $company = Company::find($payment->company_id);
        if ($company === null || $company->referred_by_user_id === null) {
            return null;
        }

        $earning = DB::transaction(function () use ($payment, $settings, $company): ?ReferralEarning {
            // Serialise per referred company so two concurrent payments can't both win.
            Company::whereKey($company->id)->lockForUpdate()->first();

            if (ReferralEarning::where('payment_id', $payment->id)->exists()) {
                return null;
            }

            if ($settings->one_time) {
                if (ReferralEarning::where('referred_company_id', $company->id)->exists()) {
                    return null;
                }

                // Only the company's FIRST completed subscription payment qualifies.
                $earlier = Payment::query()
                    ->where('company_id', $company->id)
                    ->whereNotNull('plan_id')
                    ->where('status', PaymentStatus::Completed->value)
                    ->where('id', '<', $payment->id)
                    ->exists();

                if ($earlier) {
                    return null;
                }
            }

            $rate = bcadd((string) $settings->rate_percent, '0', 2);
            $referrer = User::find($company->referred_by_user_id);
            if ($referrer === null) {
                return null;
            }

            $base = self::commissionBase($payment);
            if ($base <= 0) {
                return null;
            }
            $amount = PaymentAmount::format(
                bcdiv(bcmul(number_format($base, 2, '.', ''), $rate, 8), '100', 8),
                (string) $payment->currency,
            );

            return ReferralEarning::create([
                'referrer_user_id' => $referrer->id,
                'referrer_company_id' => $company->referred_by_company_id,
                'referred_user_id' => $company->created_by,
                'referred_company_id' => $company->id,
                'payment_id' => $payment->id,
                'source_reference' => 'SUB-PAY-'.$payment->id,
                'basis' => 'subscription',
                'base_amount' => $base,
                'rate_percent' => $rate,
                'amount' => $amount,
                'currency' => $payment->currency,
                'status' => ReferralEarningStatus::Pending,
            ]);
        });

        if ($earning) {
            $earning->referrer?->notify(new ReferralCommissionEarnedNotification($earning));
        }

        return $earning;
    }

    /**
     * What the commission is a percentage of: the subscription price the
     * company actually bought, EXCLUDING tax and EXCLUDING any payment-provider
     * fee passed through to the buyer.
     *
     * - Taxed checkout: `metadata.tax.subtotal` (pre-tax, pre-fee) — the same
     *   figure as `Payment::subtotalAmount()` from the fee pass-through work.
     * - Otherwise: `Payment::baseAmount()` / `payments.base_amount` (amount
     *   before the passed-through fee; falls back to `amount`), minus any tax
     *   recorded as `metadata.tax.tax_amount` or on the payment's invoice.
     */
    public static function commissionBase(Payment $payment): float
    {
        $tax = is_array($payment->metadata['tax'] ?? null) ? $payment->metadata['tax'] : [];

        if (isset($tax['subtotal'])) {
            $subtotal = method_exists($payment, 'subtotalAmount') ? $payment->subtotalAmount() : $tax['subtotal'];

            return round(max(0.0, (float) $subtotal), 2);
        }

        $gross = method_exists($payment, 'baseAmount')
            ? (float) $payment->baseAmount()
            : (float) ($payment->getAttribute('base_amount') ?? $payment->amount);

        $taxAmount = $tax['tax_amount'] ?? Invoice::where('payment_id', $payment->getKey())->value('tax_amount');

        return round(max(0.0, $gross - (float) ($taxAmount ?? 0)), 2);
    }

    /**
     * Mobile-facing status of one referred user.
     *
     * @return 'signed_up'|'verified'|'qualified'
     */
    public function statusFor(User $referred, ?Company $company, bool $hasEarning): string
    {
        if ($hasEarning) {
            return 'qualified';
        }

        if ($company && $company->status?->value === 'verified') {
            return 'verified';
        }

        return 'signed_up';
    }

    /**
     * The users `$referrer` referred, newest first, each annotated with
     * `referral_status` (statusFor()) and `referral_earnings` (their
     * non-cancelled commissions). Backs GET /api/v1/referrals and the
     * exporter-panel Referrals page.
     *
     * @return Collection<int, User>
     */
    public function referredUsers(User $referrer, ?int $limit = 200): Collection
    {
        $referred = User::where('referred_by_user_id', $referrer->getKey())
            ->with('companies')
            ->orderByDesc('referred_at')
            ->orderByDesc('id')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get();

        $earnings = ReferralEarning::where('referrer_user_id', $referrer->getKey())
            ->where('status', '!=', ReferralEarningStatus::Cancelled->value)
            ->get();

        return $referred->each(function (User $u) use ($earnings): void {
            $company = $u->companies->first();
            $mine = $earnings->filter(fn (ReferralEarning $e) => $e->referred_user_id === $u->id
                || ($company && $e->referred_company_id === $company->id))->values();

            $u->setAttribute('referral_status', $this->statusFor($u, $company, $mine->isNotEmpty()));
            $u->setAttribute('referral_earnings', $mine);
        });
    }

    /**
     * Funnel counts for the referrer's dashboard: everyone who signed up with
     * the code, and how many of them are (only) verified / qualified.
     *
     * @return array{signed_up: int, verified: int, qualified: int}
     */
    public function funnelFor(User $referrer): array
    {
        $statuses = $this->referredUsers($referrer, null)->pluck('referral_status');

        return [
            'signed_up' => $statuses->count(),
            'verified' => $statuses->filter(fn ($s) => $s === 'verified')->count(),
            'qualified' => $statuses->filter(fn ($s) => $s === 'qualified')->count(),
        ];
    }

    /**
     * Earned / paid / pending (pending or approved, not yet paid) commission
     * totals per currency, cancelled commissions excluded.
     *
     * @return array<string, array{earned: float, paid: float, pending: float}>
     */
    public function totalsFor(User $referrer): array
    {
        return ReferralEarning::where('referrer_user_id', $referrer->getKey())
            ->where('status', '!=', ReferralEarningStatus::Cancelled->value)
            ->get(['amount', 'currency', 'status'])
            ->groupBy(fn (ReferralEarning $e) => strtoupper((string) $e->currency))
            ->map(fn (Collection $group) => [
                'earned' => (float) $group->sum(fn ($e) => (float) $e->amount),
                'paid' => (float) $group->where('status', ReferralEarningStatus::Paid)->sum(fn ($e) => (float) $e->amount),
                'pending' => (float) $group->filter(fn ($e) => in_array($e->status, [ReferralEarningStatus::Pending, ReferralEarningStatus::Approved], true))
                    ->sum(fn ($e) => (float) $e->amount),
            ])
            ->sortKeys()
            ->all();
    }

    /** "Jean Dupont" → "Jean D." — never expose a referral's full name. */
    public static function maskName(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name)) ?: [];
        $first = $parts[0] ?? '';
        if ($first === '') {
            return 'New member';
        }

        $last = count($parts) > 1 ? ' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1)).'.' : '';

        return $first.$last;
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'qualified' => 'Qualified',
            'verified' => 'Verified',
            default => 'Signed up',
        };
    }
}
