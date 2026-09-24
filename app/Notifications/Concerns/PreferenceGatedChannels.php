<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationPreference;
use App\Notifications\Channels\ExpoPushChannel;

/**
 * Shared `via()` body for every `App\Notifications\*` class: 'database' is
 * included only if `NotificationPreference::allows($notifiable, static::TYPE,
 * 'database')`, and the Expo push channel only if the 'push' check passes.
 * Requires the using class to define `public const TYPE`.
 */
trait PreferenceGatedChannels
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (NotificationPreference::allows($notifiable, static::TYPE, 'database')) {
            $channels[] = 'database';
        }

        if (NotificationPreference::allows($notifiable, static::TYPE, 'push')) {
            $channels[] = ExpoPushChannel::class;
        }

        return $channels;
    }
}
