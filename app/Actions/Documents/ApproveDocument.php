<?php

namespace App\Actions\Documents;

use App\Events\DocumentApproved;
use App\Models\CompanyDocument;
use App\Models\User;
use App\Services\VerificationService;

class ApproveDocument
{
    public function __construct(private readonly VerificationService $verification) {}

    public function execute(CompanyDocument $document, User $actor, ?string $expiryDate = null): void
    {
        $this->verification->approveDocument($document, $actor, $expiryDate);

        DocumentApproved::dispatch($document->fresh(), $actor);
    }
}
