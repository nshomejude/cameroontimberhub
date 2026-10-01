<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a referrer wants referral commissions paid. Kept off the `users`
 * table on purpose: the PayPal email and the manual (MoMo / bank) payout
 * details are payout PII, encrypted at rest and only ever rendered masked to
 * the referrer (`maskedPaypalEmail()`, `maskedManualPayoutDetails()`).
 * Finance reads the manual details in full on the admin Referral earnings
 * table to pay XAF commissions by hand.
 */
class ReferralPayoutProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['paypal_email', 'manual_payout_details'];

    protected function casts(): array
    {
        return [
            'paypal_email' => 'encrypted',
            'manual_payout_details' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function forUser(User $user): ?self
    {
        return self::where('user_id', $user->getKey())->first();
    }

    public static function paypalEmailFor(User $user): ?string
    {
        $email = self::forUser($user)?->paypal_email;

        return filled($email) ? (string) $email : null;
    }

    public static function manualPayoutDetailsFor(User $user): ?string
    {
        $details = self::forUser($user)?->manual_payout_details;

        return filled($details) ? (string) $details : null;
    }

    public function maskedPaypalEmail(): ?string
    {
        return self::mask($this->paypal_email);
    }

    public function maskedManualPayoutDetails(): ?string
    {
        return self::maskDetails($this->manual_payout_details);
    }

    /** "jean.dupont@gmail.com" → "je********@gmail.com". */
    public static function mask(?string $email): ?string
    {
        if (blank($email) || ! str_contains((string) $email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', (string) $email, 2);
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible.str_repeat('*', max(3, mb_strlen($local) - mb_strlen($visible))).'@'.$domain;
    }

    /**
     * Free-text MoMo number / bank details, masked for display: any email
     * address in it is masked like the PayPal email, then every digit except
     * the last four (and never more than half of them) becomes "*".
     * "MTN MoMo 677 12 34 56" → "MTN MoMo *** ** 34 56".
     */
    public static function maskDetails(?string $details): ?string
    {
        if (blank($details)) {
            return null;
        }

        $text = (string) preg_replace_callback(
            '/[^\s@]+@[^\s@]+\.[^\s@]+/u',
            fn (array $m): string => (string) self::mask($m[0]),
            trim((string) $details),
        );

        $digits = preg_match_all('/\d/', $text);
        $keep = min(4, intdiv($digits, 2));
        $seen = 0;

        return (string) preg_replace_callback('/\d/', function (array $m) use (&$seen, $digits, $keep): string {
            $seen++;

            return $seen > $digits - $keep ? $m[0] : '*';
        }, $text);
    }
}
