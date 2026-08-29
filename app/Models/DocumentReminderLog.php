<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class DocumentReminderLog extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo('document_owner');
    }
}
