<?php

namespace App\Actions\Documents;

use App\Events\DocumentRejected;
use App\Models\CompanyDocument;
use App\Models\User;
use App\Services\VerificationService;

class RejectDocument
{
    public function __construct(private readonly VerificationService $verification) {}

    public function execute(CompanyDocument $document, string $reason, User $actor): void
    {
        $this->verification->rejectDocument($document, $reason, $actor);

        DocumentRejected::dispatch($document->fresh(), $actor, $reason);
    }
}
