<?php

namespace App\Models;

use App\Enums\RfqIncoterm;
use App\Enums\RfqStatus;
use App\Enums\RfqType;
use App\Models\Concerns\HasConsents;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rfq extends Model
{
    use HasConsents, HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RfqStatus::class,
            'type' => RfqType::class,
            'incoterm' => RfqIncoterm::class,
            'email_verified_at' => 'datetime',
            'is_spam' => 'boolean',
            'spam_score' => 'integer',
            'target_amount' => 'decimal:2',
            'deadline' => 'date',
            'attachments' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(RfqItem::class);
    }

    public function routings(): HasMany
    {
        return $this->hasMany(RfqCompany::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * The past order this request repeats, when it was raised as a reorder.
     *
     * Provenance and scoping: it is what proves a reorder RFQ belongs to a
     * given buyer/supplier pair, so a request from another thread never
     * resolves. It confers no price and no terms — the supplier still has to
     * quote it from scratch.
     */
    public function reorderOfOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'reorder_of_order_id');
    }

    public function isReorder(): bool
    {
        return $this->reorder_of_order_id !== null;
    }

    /** Orders awarded on this RFQ. At most one, since the award is exclusive. */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * The registered buyer this RFQ belongs to, when `buyer_email` matched an
     * account. Null for the guest path, which relies on the signed link.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Email-confirmed RFQs — the gate for every admin/exporter surface. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** RFQs of a given type — e.g. the domestic manufacturing/local-procurement flow. */
    public function scopeOfType(Builder $query, RfqType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}
