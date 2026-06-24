<?php

namespace App\Actions\Documents;

use App\Models\CompanyDocument;
use App\Models\User;
use App\Services\VerificationService;

class RequestDocumentCorrection
{
    public function __construct(private readonly VerificationService $verification) {}

    public function execute(CompanyDocument $document, string $instructions, User $actor): void
    {
        $this->verification->requestCorrection($document, $instructions, $actor);
    }
}
