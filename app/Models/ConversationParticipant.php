<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's view of one thread: where they had read up to, whether they
 * starred or archived it.
 *
 * There is no `unread_count` column on purpose. A stored counter is a second
 * source of truth that drifts the first time a write path forgets to bump it;
 * unread is instead derived from the `last_read_message_id` cursor against the
 * messages table (see MessagingService::unreadCounts(), which does it for a
 * whole page of conversations in one grouped query).
 */
class ConversationParticipant extends Model
{
    use HasFactory;

    public const ROLE_BUYER = 'buyer';

    public const ROLE_SUPPLIER = 'supplier';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_read_message_id' => 'integer',
            'last_read_at' => 'datetime',
            'is_starred' => 'boolean',
            'is_archived' => 'boolean',
            'notify_email' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isSupplier(): bool
    {
        return $this->role === self::ROLE_SUPPLIER;
    }
}
