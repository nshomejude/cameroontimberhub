<?php

namespace App\Models;

use App\Enums\RfqCompanyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RfqCompany extends Model
{
    use HasFactory;

    protected $table = 'rfq_company';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => RfqCompanyStatus::class,
            'routed_at' => 'datetime',
            'viewed_at' => 'datetime',
            'responded_at' => 'datetime',
            'declined_at' => 'datetime',
        ];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function routedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'routed_by');
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class);
    }
}
