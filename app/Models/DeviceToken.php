<?php

namespace App\Models;

use Database\Factories\DeviceTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One registered Expo push token for a mobile install. `expo_push_token` is
 * globally unique — a token belongs to a physical install, not an account,
 * so `POST /api/v1/devices` re-points the row on re-registration (see
 * `DeviceTokenController::store()`) rather than creating a duplicate.
 */
class DeviceToken extends Model
{
    /** @use HasFactory<DeviceTokenFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'expo_push_token',
        'platform',
        'device_name',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
