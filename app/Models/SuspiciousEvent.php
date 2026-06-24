<?php

namespace App\Models;

use App\Enums\SuspiciousEventSeverity;
use App\Enums\SuspiciousEventType;
use Illuminate\Database\Eloquent\Model;

class SuspiciousEvent extends Model
{
    /** Append-only event log: created_at only. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'event_type' => SuspiciousEventType::class,
            'severity'   => SuspiciousEventSeverity::class,
            'context'    => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function record(SuspiciousEventType $eventType, array $attributes = []): self
    {
        return static::create(array_merge([
            'event_type' => $eventType,
            'severity'   => SuspiciousEventSeverity::Low,
            'created_at' => now(),
        ], $attributes));
    }
}
