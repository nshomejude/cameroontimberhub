<?php

namespace App\Events;

use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly CompanyDocument $document,
        public readonly User $rejectedBy,
        public readonly ?string $reason,
    ) {}
}
