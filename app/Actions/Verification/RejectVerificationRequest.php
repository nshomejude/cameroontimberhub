<?php

namespace App\Actions\Verification;

use App\Models\User;
use App\Models\VerificationRequest;
use App\Services\VerificationService;

class RejectVerificationRequest
{
    public function __construct(private readonly VerificationService $verification) {}

    public function execute(VerificationRequest $request, string $notes, User $actor): void
    {
        $this->verification->reject($request, $notes, $actor);
    }
}
