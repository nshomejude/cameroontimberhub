<?php

use App\Actions\Company\ApproveCompanySuspension;
use App\Actions\Company\RequestCompanySuspension;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\CompanySuspensionRequest;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('creates a pending suspension request without suspending the company', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $requester = User::factory()->create();

    $request = app(RequestCompanySuspension::class)->execute($company, 'Repeated policy violations', $requester);

    expect($request)->toBeInstanceOf(CompanySuspensionRequest::class)
        ->and($request->status)->toBe('pending')
        ->and($request->requested_by)->toBe($requester->id)
        ->and($request->company_id)->toBe($company->id);

    expect($company->fresh()->status)->toBe(CompanyStatus::Verified);
});

it('suspends the company once a different staff member approves', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $requester = User::factory()->create();
    $approver = User::factory()->create();

    $request = app(RequestCompanySuspension::class)->execute($company, 'Repeated policy violations', $requester);

    app(ApproveCompanySuspension::class)->execute($request, $approver);

    expect($company->fresh()->status)->toBe(CompanyStatus::Suspended)
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->approved_by)->toBe($approver->id);
});

it('rejects self-approval by the same staff member who requested the suspension', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $requester = User::factory()->create();

    $request = app(RequestCompanySuspension::class)->execute($company, 'Repeated policy violations', $requester);

    expect(fn () => app(ApproveCompanySuspension::class)->execute($request, $requester))
        ->toThrow(ValidationException::class);

    expect($company->fresh()->status)->toBe(CompanyStatus::Verified)
        ->and($request->fresh()->status)->toBe('pending');
});

it('rejects approving a request that has already been decided', function () {
    $company = Company::factory()->create(['status' => CompanyStatus::Verified]);
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $secondApprover = User::factory()->create();

    $request = app(RequestCompanySuspension::class)->execute($company, 'Repeated policy violations', $requester);

    app(ApproveCompanySuspension::class)->execute($request, $approver);

    expect(fn () => app(ApproveCompanySuspension::class)->execute($request->fresh(), $secondApprover))
        ->toThrow(ValidationException::class);
});
