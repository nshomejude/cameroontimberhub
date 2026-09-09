<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * One row per (API key, calendar day) — see the migration doc block for why
 * this is daily-aggregated rather than per-request. Written to by
 * App\Http\Middleware\RecordApiKeyUsage via an atomic upsert, read by the
 * admin usage view and the company-facing "my usage" view.
 */
class ApiKeyUsageDaily extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'request_count' => 'integer',
        ];
    }

    public function personalAccessToken(): BelongsTo
    {
        return $this->belongsTo(PersonalAccessToken::class);
    }
}
