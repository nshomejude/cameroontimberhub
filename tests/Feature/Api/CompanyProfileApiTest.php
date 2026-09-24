<?php

use App\Models\Company;
use App\Models\CompanyContact;
use App\Models\CompanyDocument;
use App\Models\CompanyGallery;
use App\Models\Species;
use App\Models\User;
use App\Models\VerificationRequest;
use Database\Seeders\RolesAndPermissionsSeeder;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function companyProfileApiSupplier(array $companyAttributes = []): array
{
    $user = User::factory()->create();
    $company = Company::factory()->create($companyAttributes);
    $company->users()->attach($user, ['role' => 'owner', 'is_primary' => true]);

    return [$user, $company];
}

/* --------------------------------------------------------------- GET /company */

it('requires supplier auth (a plain buyer 403s)', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')->getJson('/api/v1/company')
        ->assertForbidden();
});

it('refuses an unauthenticated call', function () {
    $this->getJson('/api/v1/company')->assertUnauthorized();
});

it('returns the real profile data for the caller own company', function () {
    [$user, $company] = companyProfileApiSupplier([
        'legal_name' => 'Cameroon Hardwoods SARL',
        'description' => str_repeat('A', 60),
        'region' => 'Littoral',
    ]);

    $species = Species::factory()->create(['common_name' => 'Iroko']);
    $company->species()->attach($species);
    CompanyContact::factory()->create(['company_id' => $company->getKey(), 'name' => 'Jane Doe']);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/company')
        ->assertOk()
        ->assertJsonPath('data.legal_name', 'Cameroon Hardwoods SARL')
        ->assertJsonPath('data.region', 'Littoral');

    expect($response->json('data.species.0.common_name'))->toBe('Iroko')
        ->and($response->json('data.contacts.0.name'))->toBe('Jane Doe')
        ->and($response->json('data.max_gallery_images'))->toBeInt();
});

/* --------------------------------------------------------------- PATCH /company */

it('updates scalar fields and persists them', function () {
    [$user, $company] = companyProfileApiSupplier(['description' => str_repeat('A', 60)]);

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/company', [
        'trade_name' => 'CamHard',
        'city' => 'Douala',
        'website_url' => 'https://camhard.example.com',
    ])->assertOk()
        ->assertJsonPath('data.trade_name', 'CamHard')
        ->assertJsonPath('data.city', 'Douala');

    $company->refresh();

    expect($company->trade_name)->toBe('CamHard')
        ->and($company->city)->toBe('Douala')
        ->and($company->website_url)->toBe('https://camhard.example.com');
});

it('rejects a too-short description', function () {
    [$user] = companyProfileApiSupplier();

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/company', [
        'description' => 'too short',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('description', 'error.details');
});

it('rejects an invalid email', function () {
    [$user] = companyProfileApiSupplier();

    $this->actingAs($user, 'sanctum')->patchJson('/api/v1/company', [
        'email' => 'not-an-email',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('email', 'error.details');
});

it('replaces contacts entirely rather than merging', function () {
    [$user, $company] = companyProfileApiSupplier();

    $old = CompanyContact::factory()->create(['company_id' => $company->getKey(), 'name' => 'Old Contact']);

    $response = $this->actingAs($user, 'sanctum')->patchJson('/api/v1/company', [
        'contacts' => [
            ['name' => 'New Contact', 'email' => 'new@example.com', 'is_public' => true],
        ],
    ])->assertOk();

    expect($response->json('data.contacts'))->toHaveCount(1)
        ->and($response->json('data.contacts.0.name'))->toBe('New Contact');

    expect(CompanyContact::query()->whereKey($old->getKey())->exists())->toBeFalse();
    expect($company->fresh()->contacts()->pluck('name')->all())->toBe(['New Contact']);
});

it('gives a plain buyer no company 404 style response is not reached (403 at the gate)', function () {
    $buyer = User::factory()->create();

    $this->actingAs($buyer, 'sanctum')->patchJson('/api/v1/company', ['trade_name' => 'x'])
        ->assertForbidden();
});

/* --------------------------------------------------------------- GET /company/onboarding */

it('returns onboarding steps with the right done flags for a fully-populated company', function () {
    [$user, $company] = companyProfileApiSupplier(['profile_completion' => 80]);

    $species = Species::factory()->create();
    $company->species()->attach($species);
    CompanyContact::factory()->create(['company_id' => $company->getKey()]);
    CompanyGallery::query()->create([
        'company_id' => $company->getKey(),
        'image_path' => 'companies/gallery/test.jpg',
    ]);
    CompanyDocument::factory()->create(['company_id' => $company->getKey()]);
    VerificationRequest::factory()->create(['company_id' => $company->getKey()]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/onboarding')
        ->assertOk();

    $steps = collect($response->json('data.steps'))->keyBy('key');

    expect($steps['profile_created']['done'])->toBeTrue()
        ->and($steps['basic_profile']['done'])->toBeTrue()
        ->and($steps['species']['done'])->toBeTrue()
        ->and($steps['contacts']['done'])->toBeTrue()
        ->and($steps['gallery']['done'])->toBeTrue()
        ->and($steps['documents']['done'])->toBeTrue()
        ->and($steps['verification_submitted']['done'])->toBeTrue()
        ->and($response->json('data.completed_count'))->toBe($response->json('data.total_count'));
});

it('returns onboarding steps with false flags for a bare company', function () {
    [$user] = companyProfileApiSupplier(['profile_completion' => 10]);

    $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/company/onboarding')
        ->assertOk();

    $steps = collect($response->json('data.steps'))->keyBy('key');

    expect($steps['basic_profile']['done'])->toBeFalse()
        ->and($steps['species']['done'])->toBeFalse()
        ->and($steps['contacts']['done'])->toBeFalse()
        ->and($steps['gallery']['done'])->toBeFalse()
        ->and($steps['documents']['done'])->toBeFalse()
        ->and($steps['verification_submitted']['done'])->toBeFalse()
        ->and($response->json('data.completed_count'))->toBe(1);
});
