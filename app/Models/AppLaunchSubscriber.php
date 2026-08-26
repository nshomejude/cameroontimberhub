<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A visitor who asked to be told when the buyer mobile app ships.
 *
 * Deliberately its own table: see the migration for why `leads` and
 * `company_inquiries` do not fit.
 */
class AppLaunchSubscriber extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    /**
     * Record an address, treating a repeat sign-up as a success rather than a
     * duplicate row or a validation error — the visitor asked for the same
     * thing twice and should see the same confirmation both times.
     */
    public static function subscribe(string $email, string $platform = 'any', ?string $ip = null): self
    {
        $normalised = mb_strtolower(trim($email));

        return static::updateOrCreate(
            ['email_normalised' => $normalised],
            [
                'email' => trim($email),
                'platform' => $platform,
                'source' => 'mobile-app-page',
                'ip_address' => $ip,
            ],
        );
    }
}
