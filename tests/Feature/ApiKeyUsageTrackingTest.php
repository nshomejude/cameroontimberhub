<?php

use App\Enums\CompanyUserRole;
use App\Models\ApiKeyMeta;
use App\Models\ApiKeyUsageDaily;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

it('increments the correct day\'s usage row for the correct key when a request hits /api/v1', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-key');

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    $row = ApiKeyUsageDaily::query()
        ->where('personal_access_token_id', $token->accessToken->id)
        ->where('date', now()->toDateString())
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->request_count)->toBe(1);
});

it('does not increment another key\'s usage row', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $tokenA = $userA->createToken('key-a');
    $tokenB = $userB->createToken('key-b');

    $this->withHeader('Authorization', 'Bearer '.$tokenA->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk();

    expect(ApiKeyUsageDaily::query()->where('personal_access_token_id', $tokenA->accessToken->id)->exists())->toBeTrue()
        ->and(ApiKeyUsageDaily::query()->where('personal_access_token_id', $tokenB->accessToken->id)->exists())->toBeFalse();
});

it('accumulates repeated requests against the same key instead of overwriting the count', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-key');

    for ($i = 0; $i < 3; $i++) {
        $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();
    }

    $row = ApiKeyUsageDaily::query()
        ->where('personal_access_token_id', $token->accessToken->id)
        ->where('date', now()->toDateString())
        ->first();

    expect($row->request_count)->toBe(3);
});

it('never blocks the underlying API response when usage recording fails', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test-key');

    DB::shouldReceive('statement')->once()->andThrow(new RuntimeException('forced failure'));
    Log::shouldReceive('error')->once();

    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)
        ->getJson('/api/v1/auth/me')
        ->assertOk();
});

it('is a no-op for unauthenticated public catalogue requests', function () {
    $this->getJson('/api/v1/products')->assertOk();

    expect(ApiKeyUsageDaily::query()->count())->toBe(0);
});

function ownedCompanyWithUser(): array
{
    $company = Company::factory()->create();
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);

    return [$company, $user];
}

function makeApiKeyMetaFor(Company $company, User $requester, User $approver): ApiKeyMeta
{
    $token = $requester->createToken('company-key');

    return ApiKeyMeta::query()->create([
        'personal_access_token_id' => $token->accessToken->id,
        'company_id' => $company->id,
        'rate_limit_tier' => 'standard',
        'requested_by' => $requester->id,
        'approved_by' => $approver->id,
    ]);
}

it('admin usage view is only reachable with the api-keys.manage permission', function () {
    (new \Database\Seeders\RolesAndPermissionsSeeder())->run();

    [$company, $companyUser] = ownedCompanyWithUser();
    $approver = User::factory()->create();
    makeApiKeyMetaFor($company, $companyUser, $approver);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $noPermAdmin = User::factory()->create();

    expect(\App\Filament\Resources\ApiKeyUsages\ApiKeyUsageResource::canViewAny())->toBeFalse();

    $this->actingAs($admin);
    expect(\App\Filament\Resources\ApiKeyUsages\ApiKeyUsageResource::canViewAny())->toBeTrue();

    $this->actingAs($noPermAdmin);
    expect(\App\Filament\Resources\ApiKeyUsages\ApiKeyUsageResource::canViewAny())->toBeFalse();
});

it('scopes the company-facing usage view to the signed-in company only', function () {
    [$companyA, $userA] = ownedCompanyWithUser();
    [$companyB, $userB] = ownedCompanyWithUser();
    $approver = User::factory()->create();

    $metaA = makeApiKeyMetaFor($companyA, $userA, $approver);
    $metaB = makeApiKeyMetaFor($companyB, $userB, $approver);

    $this->actingAs($userA);

    $visibleIds = \App\Filament\Exporter\Resources\ApiUsages\ApiUsageResource::getEloquentQuery()->pluck('id');

    expect($visibleIds)->toContain($metaA->id)
        ->and($visibleIds)->not->toContain($metaB->id);
});
