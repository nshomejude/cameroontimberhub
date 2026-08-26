<?php

namespace App\Models;

use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * One entry in a thread — prose, a platform note, or a structured card.
 *
 * `payload` is the immutable half and `related` the live half. An order card,
 * for example, snapshots the total that was agreed when it was posted (so the
 * history of the conversation cannot be rewritten by a later edit) but reads
 * `status` straight off the related Order, so the card in the thread always
 * shows where the order actually is today.
 */
class Message extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'payload' => 'array',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function senderCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'sender_company_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    /** The live record this message references (Order, Product, …), if any. */
    public function related(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'related_type', 'related_id');
    }

    /* ------------------------------------------------------------ helpers */

    /** A system message has no author. */
    public function isSystem(): bool
    {
        return $this->type === MessageType::System;
    }

    /** True when $user wrote this message (drives right-alignment + delete). */
    public function isFrom(?User $user): bool
    {
        return $user !== null
            && $this->sender_user_id !== null
            && (int) $this->sender_user_id === (int) $user->getKey();
    }

    /** Only the author may delete, and only their own prose. */
    public function isDeletableBy(?User $user): bool
    {
        return $this->isFrom($user) && $this->type->isDeletable();
    }

    /** Read a snapshotted value out of the payload. */
    public function payloadValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    /**
     * Inbox preview. Plain text only — the value is escaped at render time and
     * never contains markup of its own.
     */
    public function preview(int $limit = 110): string
    {
        $body = trim((string) $this->body);

        if ($body === '') {
            $body = $this->type->previewFallback();
        }

        return Str::limit(preg_replace('/\s+/u', ' ', $body) ?? '', $limit);
    }
}
