<?php

namespace App\Services\Referrals;

use App\Enums\PaymentStatus;
use App\Enums\ReferralEarningStatus;
use App\Models\Company;
use App\Models\Payment;
use App\Models\ReferralEarning;
use App\Models\ReferralSetting;
use App\Models\User;
use App\Notifications\ReferralCommissionEarnedNotification;
use App\Notifications\ReferralSignedUpNotification;
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
 *   referred company's FIRST completed subscription payment. Renewals (any
 *   later completed plan payment) earn nothing while `one_time` is on.
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
            } catch (\Illuminate\Database\UniqueConstraintViolationException) {
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

            $rate = (float) $settings->rate_percent;
            $referrer = User::find($company->referred_by_user_id);
            if ($referrer === null) {
                return null;
            }

            return ReferralEarning::create([
                'referrer_user_id' => $referrer->id,
                'referrer_company_id' => $company->referred_by_company_id,
                'referred_user_id' => $company->created_by,
                'referred_company_id' => $company->id,
                'payment_id' => $payment->id,
                'source_reference' => 'SUB-PAY-'.$payment->id,
                'basis' => 'subscription',
                'base_amount' => $payment->amount,
                'rate_percent' => $rate,
                'amount' => round(((float) $payment->amount) * $rate / 100, 2),
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
