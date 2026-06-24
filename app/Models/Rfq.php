<?php

namespace App\Models;

use App\Enums\RfqIncoterm;
use App\Enums\RfqStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rfq extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status'           => RfqStatus::class,
            'incoterm'         => RfqIncoterm::class,
            'email_verified_at' => 'datetime',
            'is_spam'          => 'boolean',
            'spam_score'       => 'integer',
            'target_amount'    => 'decimal:2',
            'deadline'         => 'date',
            'attachments'      => 'array',
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

    /** Email-confirmed RFQs — the gate for every admin/exporter surface. */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('email_verified_at');
    }

    public function isVerified(): bool
    {
        return $this->email_verified_at !== null;
    }
}
