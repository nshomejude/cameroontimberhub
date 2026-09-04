<?php

use App\Actions\Verification\ApproveVerificationRevocation;
use App\Actions\Verification\RequestVerificationRevocation;
use App\Enums\BadgeStatus;
use App\Models\Company;
use App\Models\User;
use App\Models\VerificationBadge;
use App\Models\VerificationRevocationRequest;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function governanceStaff(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

// --- Regression: nothing existing was removed from admin/super_admin ---

it('keeps every pre-existing admin permission intact', function () {
    $preExisting = [
        'companies.view', 'companies.manage',
        'documents.review', 'verification.review',
        'badges.issue', 'badges.revoke',
        'species.manage', 'products.manage',
        'rfqs.triage', 'rfqs.route', 'inquiries.review',
        'pages.manage', 'audit.view',
        'certificates.manage',
    ];

    $admin = Role::findByName('admin', 'web');
    $adminPermissions = $admin->permissions->pluck('name')->all();

    foreach ($preExisting as $permission) {
        expect($adminPermissions)->toContain($permission);
    }
});

it('keeps super_admin holding every permission, old and new', function () {
    $superAdmin = Role::findByName('super_admin', 'web');
    $superAdminPermissions = $superAdmin->permissions->pluck('name')->all();

    foreach (RolesAndPermissionsSeeder::PERMISSIONS as $permission) {
        expect($superAdminPermissions)->toContain($permission);
    }
});

it('keeps every other existing role matrix entry intact', function () {
    foreach (['verification_officer', 'content_manager'] as $roleName) {
        $role = Role::findByName($roleName, 'web');
        $rolePermissions = $role->permissions->pluck('name')->all();

        foreach (RolesAndPermissionsSeeder::MATRIX[$roleName] as $permission) {
            expect($rolePermissions)->toContain($permission);
        }
    }
});

// --- Segregation of duties (blueprint §88) ---

it('lets a compliance_officer manage compliance resources but not billing', function () {
    $this->actingAs(governanceStaff('compliance_officer'));

    $this->get('/admin/compliance-rules')->assertOk();
    $this->get('/admin/compliance-cases')->assertOk();
    $this->get('/admin/plans')->assertForbidden();
});

it('lets a billing_officer manage plans but not verification or compliance rules', function () {
    $this->actingAs(governanceStaff('billing_officer'));

    $this->get('/admin/plans')->assertOk();
    $this->get('/admin/verification-requests')->assertForbidden();
    $this->get('/admin/compliance-rules')->assertForbidden();
});

// --- Two-person control for verification revocation (blueprint §89) ---

it('creates a pending revocation request without revoking the badge', function () {
    $badge = VerificationBadge::factory()->create(['status' => BadgeStatus::Active]);
    $requester = governanceStaff('verification_officer');

    $request = app(RequestVerificationRevocation::class)->execute($badge->company, 'Fraudulent documents', $requester);

    expect($request->status)->toBe('pending')
        ->and($request->requested_by)->toBe($requester->id)
        ->and($badge->fresh()->status)->toBe(BadgeStatus::Active);
});

it('blocks the same user who requested the revocation from also approving it', function () {
    $badge = VerificationBadge::factory()->create(['status' => BadgeStatus::Active]);
    $requester = governanceStaff('verification_officer');

    $request = app(RequestVerificationRevocation::class)->execute($badge->company, 'Fraudulent documents', $requester);

    app(ApproveVerificationRevocation::class)->execute($request, $requester);
})->throws(ValidationException::class);

it('completes the revocation when a different authorized staff member approves it', function () {
    $badge = VerificationBadge::factory()->create(['status' => BadgeStatus::Active]);
    $requester = governanceStaff('verification_officer');
    $approver = governanceStaff('verification_officer');

    $request = app(RequestVerificationRevocation::class)->execute($badge->company, 'Fraudulent documents', $requester);

    app(ApproveVerificationRevocation::class)->execute($request, $approver);

    expect($badge->fresh()->status)->toBe(BadgeStatus::Revoked)
        ->and($request->fresh()->status)->toBe('approved')
        ->and($request->fresh()->approved_by)->toBe($approver->id);
});

it('does not let a decided revocation request be approved again', function () {
    $badge = VerificationBadge::factory()->create(['status' => BadgeStatus::Active]);
    $requester = governanceStaff('verification_officer');
    $approver = governanceStaff('verification_officer');
    $thirdParty = governanceStaff('verification_officer');

    $request = app(RequestVerificationRevocation::class)->execute($badge->company, 'Fraudulent documents', $requester);
    app(ApproveVerificationRevocation::class)->execute($request, $approver);

    app(ApproveVerificationRevocation::class)->execute($request->fresh(), $thirdParty);
})->throws(ValidationException::class);
