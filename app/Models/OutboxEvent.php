<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row in the transactional outbox (architecture plan, Task 0.2). See
 * App\Support\Events\RecordsOutboxEvents for how rows are written, and
 * App\Jobs\RelayOutboxEventsJob for how they get published.
 *
 * Only `created_at` is tracked (no `updated_at` column exists), since a row
 * is written once and afterward only `published_at`/`attempts` mutate.
 */
class OutboxEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
