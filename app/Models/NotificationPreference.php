<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's notification delivery preferences.
 *
 * `channels` gates the delivery MECHANISM (push/email) regardless of event
 * type. `types` gates whether a given event type is recorded AT ALL — a
 * `false` there suppresses even the `database` channel write, per
 * `allows()` below. Only the 4 original type keys ship a default; a type
 * introduced later that is absent from the map is treated as allowed
 * (`?? true`), so new event types are not silently blocked by a preferences
 * row created before they existed.
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'user_id',
        'channels',
        'types',
    ];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'types' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The known, settable keys — used to reject unknown keys on PATCH with a 422. */
    public const CHANNEL_KEYS = ['push', 'email'];

    public const TYPE_KEYS = [
        'quote_received',
        'order_status_changed',
        'message_received',
        'dispute_reply',
    ];

    public static function forUser(User $user): self
    {
        return self::firstOrCreate(
            ['user_id' => $user->getKey()],
            [
                'channels' => ['push' => true, 'email' => true],
                'types' => array_fill_keys(self::TYPE_KEYS, true),
            ],
        );
    }

    /**
     * Should this user receive this notification `type` over this `channel`?
     *
     * `channel` is either 'database' (gated by `types.{type}`) or 'push'
     * (gated by `channels.push`, regardless of type — a user can mute push
     * entirely while still keeping every type as an in-app/database entry).
     * Any other channel value is allowed by default (nothing currently
     * calls this for 'email').
     */
    public static function allows(User $user, string $type, string $channel): bool
    {
        $pref = self::forUser($user);

        return match ($channel) {
            'database' => (bool) ($pref->types[$type] ?? true),
            'push' => (bool) ($pref->channels['push'] ?? true),
            default => true,
        };
    }
}
