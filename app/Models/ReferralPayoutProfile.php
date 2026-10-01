<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a referrer wants referral commissions paid. Kept off the `users`
 * table on purpose: the PayPal email is payout PII, encrypted at rest and
 * only ever rendered masked (`maskedPaypalEmail()`).
 */
class ReferralPayoutProfile extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['paypal_email'];

    protected function casts(): array
    {
        return [
            'paypal_email' => 'encrypted',
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

    public function maskedPaypalEmail(): ?string
    {
        return self::mask($this->paypal_email);
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
}
