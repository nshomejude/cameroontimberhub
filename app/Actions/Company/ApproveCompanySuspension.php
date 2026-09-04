<?php

namespace App\Actions\Company;

use App\Models\CompanySuspensionRequest;
use App\Models\User;
use App\Services\CompanyStatusService;
use Illuminate\Validation\ValidationException;

/**
 * Second step of the two-person suspension control (blueprint §88-89).
 * Enforces that the approving user is NOT the same person who requested
 * the suspension, then actually transitions the company to Suspended via
 * CompanyStatusService.
 */
class ApproveCompanySuspension
{
    public function __construct(private readonly CompanyStatusService $statusService) {}

    public function execute(CompanySuspensionRequest $request, User $approvedBy): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages(['status' => 'This suspension request has already been decided.']);
        }

        if ((int) $request->requested_by === (int) $approvedBy->getKey()) {
            throw ValidationException::withMessages(['approved_by' => 'The suspension must be approved by a different staff member than the one who requested it.']);
        }

        $this->statusService->suspend($request->company, $request->reason, $approvedBy);

        $request->update([
            'status' => 'approved',
            'approved_by' => $approvedBy->getKey(),
        ]);
    }
}
