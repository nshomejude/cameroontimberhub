<?php

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\ConversationTopic;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A buyer <-> supplier-company thread.
 *
 * Exactly two sides by construction: `user_id` is the buyer account and
 * `company_id` the supplier company. Authorisation therefore never needs a
 * membership table walk on the buyer side, and on the supplier side it is
 * "is this user a member of company_id" — the same rule the exporter panel
 * already uses via Company::scopeDashboardOwned().
 */
class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'topic' => ConversationTopic::class,
            'last_message_at' => 'datetime',
        ];
    }

    /* --------------------------------------------------------- relations */

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The buyer account. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** Newest message, eager-loadable for the inbox preview without an N+1. */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    /* ------------------------------------------------------------ access */

    /**
     * The one membership test. A user is a participant when they are the buyer
     * on this thread, or a member of the supplier company.
     *
     * `relationLoaded` guards keep this free of queries inside a rendered list.
     */
    public function includes(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ((int) $this->user_id === (int) $user->getKey()) {
            return true;
        }

        return $user->companies()->whereKey($this->company_id)->exists();
    }

    /** Buyer-side scope: threads this account owns. */
    public function scopeForBuyer(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * Supplier-side scope: threads belonging to any company this user is a
     * member of. Nothing here reads an id from the request.
     */
    public function scopeForSupplier(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'company',
            fn (Builder $c) => $c->whereHas('users', fn (Builder $u) => $u->whereKey($user->getKey()))
        );
    }

    /** Either side, for a surface that serves both. */
    public function scopeForParticipant(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('user_id', $user->getKey())
            ->orWhereHas('company', fn (Builder $c) => $c->whereHas('users', fn (Builder $u) => $u->whereKey($user->getKey())))
        );
    }

    /* ----------------------------------------------------------- display */

    /**
     * What the *other* side is called, from $user's point of view. Never
     * invented: the supplier is the company's display name, the buyer is the
     * account name.
     */
    public function counterpartyName(User $user): string
    {
        return (int) $this->user_id === (int) $user->getKey()
            ? (string) $this->company?->name
            : (string) ($this->user?->name ?? 'Buyer');
    }

    /**
     * Counterparty avatar. Company logos are real files (Company::logoUrl());
     * buyers have no avatar column, so their side falls back to initials and
     * this returns null rather than a stock face.
     */
    public function counterpartyLogoUrl(User $user): ?string
    {
        return (int) $this->user_id === (int) $user->getKey()
            ? $this->company?->logoUrl()
            : null;
    }

    public function counterpartyInitials(User $user): string
    {
        return collect(explode(' ', trim($this->counterpartyName($user))))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('') ?: '?';
    }

    /** True when the counterparty company carries live verification. */
    public function counterpartyIsVerified(User $user): bool
    {
        return (int) $this->user_id === (int) $user->getKey()
            && $this->company?->verified_at !== null;
    }

    public function subjectLine(): string
    {
        return $this->subject ?: $this->topic->label();
    }
}
