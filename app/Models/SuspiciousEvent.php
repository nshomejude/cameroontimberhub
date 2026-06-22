<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuspiciousEvent extends Model
{
    /** Append-only event log: created_at only. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(string $eventType, array $attributes = []): self
    {
        return static::create(array_merge([
            'event_type' => $eventType,
            'severity' => 'low',
            'created_at' => now(),
        ], $attributes));
    }
}
