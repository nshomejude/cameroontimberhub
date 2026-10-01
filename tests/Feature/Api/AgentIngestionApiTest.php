<?php

/*
 * Agent Ingestion Gateway (docs/api/AGENT_INGESTION.md): machine-principal
 * keys, enforcement middleware, supplier/product upsert, batch, dedup,
 * idempotency and admin moderation.
 */

use App\Actions\Agent\ModerateAgentSubmission;
use App\Actions\ApiKeys\IssueAgentApiKey;
use App\Actions\ApiKeys\RevokeApiKey;
use App\Enums\CompanyStatus;
use App\Enums\CompanyUserRole;
use App\Enums\ProductStatus;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\ApiKeyMeta;
use App\Models\Company;
use App\Models\Product;
use App\Models\Species;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/** @return array{token: string, meta: ApiKeyMeta, user: User} */
function agentKey(array $abilities = ['agent:ingest', 'agent:read'], string $name = 'Hermes', ?int $days = 365): array
{
    $r = app(IssueAgentApiKey::class)->execute($name, $abilities, $days);

    return ['token' => $r['plain_text_token'], 'meta' => $r['meta'], 'user' => $r['user']];
}

function agentHeaders(string $token, array $extra = []): array
{
    app('auth')->forgetGuards();

    return array_merge(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'], $extra);
}

function agentSupplierPayload(array $overrides = []): array
{
    return array_merge([
        'external_id' => 'hermes-001',
        'legal_name' => 'Bois Tropicaux du Littoral SARL',
        'trade_name' => 'BTL Timber',
        'description' => 'Sawmill and exporter of tropical hardwoods.',
        'supplier_type' => 'exporter',
        'email' => 'sales@btl-timber.cm',
        'phone' => '+237690000000',
        'website_url' => 'https://btl-timber.cm',
        'city' => 'Douala',
        'region' => 'Littoral',
        'export_markets' => ['FR', 'CN'],
        'contacts' => [['name' => 'Jean Mbarga', 'role' => 'Sales', 'email' => 'jean@btl-timber.cm', 'whatsapp' => '+237690000001']],
        'source_url' => 'https://example.org/directory/btl',
        'evidence' => ['Listed in MINFOF exporter registry 2026'],
        'confidence' => 0.82,
    ], $overrides);
}

function agentProductPayload(array $overrides = []): array
{
    return array_merge([
        'external_id' => 'hermes-p-001',
        'name' => 'Iroko Sawn Timber KD 50mm',
        'product_type' => 'sawn_timber',
        'species_id' => Species::factory()->create()->id,
        'price_amount' => 650000,
        'price_currency' => 'XAF',
        'price_unit' => 'm3',
    ], $overrides);
}

/* ------------------------------------------------------- keys & auth */

it('creates an agent key on a service-account user with no company and no panel access', function () {
    $this->artisan('agent:create-key', ['name' => 'Hermes'])->assertSuccessful();

    $user = User::where('email', 'agent+hermes@agents.cameroontimberhub.local')->firstOrFail();
    $meta = ApiKeyMeta::where('kind', 'agent')->firstOrFail();

    expect($user->hasRole('agent'))->toBeTrue()
        ->and($user->companies()->exists())->toBeFalse()
        ->and($meta->company_id)->toBeNull()
        ->and($meta->rate_limit_tier)->toBe('elevated')
        ->and($meta->personalAccessToken->abilities)->toBe(['agent:ingest', 'agent:read'])
        ->and($user->canAccessPanel(filament()->getPanel('admin')))->toBeFalse()
        ->and($user->canAccessPanel(filament()->getPanel('exporter')))->toBeFalse();

    $this->artisan('agent:list-keys')->assertSuccessful();
});

it('rejects unauthenticated requests and requires the right ability', function () {
    $this->getJson('/api/v1/agent/reference')->assertUnauthorized();

    $readOnly = agentKey(['agent:read'], 'Reader');
    $this->getJson('/api/v1/agent/reference', agentHeaders($readOnly['token']))->assertOk()
        ->assertJsonStructure(['data' => ['species', 'product_types', 'price_units', 'currencies', 'regions', 'supplier_types', 'organisation_types']]);

    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($readOnly['token']))
        ->assertForbidden()->assertJsonPath('error.code', 'missing_ability');
});

it('rejects a revoked agent key with token_revoked', function () {
    $key = agentKey();
    $this->getJson('/api/v1/agent/reference', agentHeaders($key['token']))->assertOk();

    app(RevokeApiKey::class)->execute($key['meta']->personalAccessToken);

    $this->getJson('/api/v1/agent/reference', agentHeaders($key['token']))
        ->assertUnauthorized()->assertJsonPath('error.code', 'token_revoked');
});

it('enforces revocation for ordinary company keys on every API route', function () {
    $user = User::factory()->create();
    $token = $user->createToken('device');
    ApiKeyMeta::create(['personal_access_token_id' => $token->accessToken->id, 'kind' => 'company', 'revoked_at' => now()]);

    $this->getJson('/api/v1/auth/me', agentHeaders($token->plainTextToken))
        ->assertUnauthorized()->assertJsonPath('error.code', 'token_revoked');
});

it('rejects an expired agent key', function () {
    $key = agentKey();
    $key['meta']->personalAccessToken->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->getJson('/api/v1/agent/reference', agentHeaders($key['token']))->assertUnauthorized();
});

it('confines agent keys to the agent prefix (no buyer/supplier/generic routes)', function () {
    $key = agentKey();

    $this->getJson('/api/v1/rfqs', agentHeaders($key['token']))
        ->assertForbidden()->assertJsonPath('error.code', 'agent_scope_violation');
    $this->getJson('/api/v1/supplier/products', agentHeaders($key['token']))
        ->assertForbidden()->assertJsonPath('error.code', 'agent_scope_violation');
    $this->getJson('/api/v1/notifications', agentHeaders($key['token']))
        ->assertForbidden()->assertJsonPath('error.code', 'agent_scope_violation');
});

it('does not let a normal owner token reach agent routes', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->create();
    $company->users()->attach($owner->id, ['role' => CompanyUserRole::Owner, 'is_primary' => true]);
    $token = $owner->createToken('owner', ['*'])->plainTextToken;

    $this->getJson('/api/v1/agent/reference', agentHeaders($token))
        ->assertForbidden()->assertJsonPath('error.code', 'agent_token_required');
    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($token))
        ->assertForbidden();
});

/* --------------------------------------------------------- suppliers */

it('creates a hidden, review-pending supplier and upserts it idempotently by external_id', function () {
    $key = agentKey();
    $species = Species::factory()->create();

    $res = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(['species' => [$species->slug]]), agentHeaders($key['token']))
        ->assertCreated()
        ->assertJsonPath('result', 'created')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.needs_review', true)
        ->assertJsonPath('data.publicly_visible', false)
        ->assertJsonPath('data.source', 'agent:hermes');

    $company = Company::findOrFail($res->json('data.id'));
    expect($company->created_by)->toBe($key['user']->id)
        ->and($company->ingested_by_token_id)->toBe($key['meta']->personal_access_token_id)
        ->and($company->ingestion_meta['confidence'])->toBe(0.82)
        ->and($company->species()->pluck('species.id')->all())->toBe([$species->id])
        ->and($company->contacts()->count())->toBe(1)
        ->and($company->exportMarkets()->count())->toBe(2)
        ->and(Company::publiclyVisible()->whereKey($company->id)->exists())->toBeFalse();

    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(['description' => 'Updated']), agentHeaders($key['token']))
        ->assertOk()->assertJsonPath('result', 'updated')->assertJsonPath('data.id', $company->id);

    expect(Company::where('external_id', 'hermes-001')->count())->toBe(1)
        ->and($company->fresh()->description)->toBe('Updated');

    $activity = Activity::where('log_name', 'agent-ingestion')->where('event', 'agent_supplier_created')->firstOrFail();
    expect($activity->causer_id)->toBe($key['user']->id)
        ->and($activity->properties['token_id'])->toBe($key['meta']->personal_access_token_id)
        ->and($activity->properties)->toHaveKey('request_id');
});

it('prohibits status / verification fields', function () {
    $key = agentKey();

    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(['status' => 'verified', 'is_featured' => true]), agentHeaders($key['token']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['status', 'is_featured']]]);
});

it('refuses to touch a human-owned company that matches (supplier_owned)', function () {
    $key = agentKey();
    $human = Company::factory()->create(['registration_number' => 'RC/DLA/2020/B/123', 'status' => CompanyStatus::Verified]);

    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(['registration_number' => 'rc/dla/2020/b/123']), agentHeaders($key['token']))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'supplier_owned')
        ->assertJsonPath('error.details.existing_id', $human->id);

    $this->postJson("/api/v1/agent/suppliers/{$human->id}/products", agentProductPayload(), agentHeaders($key['token']))
        ->assertStatus(409)->assertJsonPath('error.code', 'supplier_owned');

    expect(Company::count())->toBe(1);
});

it('reports a duplicate of another agent-sourced supplier without creating', function () {
    $key = agentKey();
    $first = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token']))->assertCreated();

    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(['external_id' => 'other-id', 'website_url' => 'https://www.btl-timber.cm/about']), agentHeaders($key['token']))
        ->assertOk()
        ->assertJsonPath('result', 'duplicate')
        ->assertJsonPath('existing_id', $first->json('data.id'));

    expect(Company::count())->toBe(1);
});

it('lets agents look up supplier status by id and external_id', function () {
    $key = agentKey();
    $id = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token']))->json('data.id');

    $this->getJson("/api/v1/agent/suppliers/{$id}", agentHeaders($key['token']))->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson('/api/v1/agent/suppliers?external_id=hermes-001', agentHeaders($key['token']))->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson('/api/v1/agent/suppliers?external_id=nope', agentHeaders($key['token']))->assertNotFound();
});

/* ---------------------------------------------------------- products */

it('forces agent products to draft + needs_review and upserts by external_id', function () {
    $key = agentKey();
    $id = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token']))->json('data.id');

    $payload = agentProductPayload();
    $this->postJson("/api/v1/agent/suppliers/{$id}/products", $payload + ['status' => 'active'], agentHeaders($key['token']))
        ->assertUnprocessable();

    $res = $this->postJson("/api/v1/agent/suppliers/{$id}/products", $payload, agentHeaders($key['token']))
        ->assertCreated()
        ->assertJsonPath('result', 'created')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.needs_review', true);

    expect(Product::findOrFail($res->json('data.id'))->public_id)->not->toBeEmpty();

    $this->postJson("/api/v1/agent/suppliers/{$id}/products", array_merge($payload, ['name' => 'Renamed']), agentHeaders($key['token']))
        ->assertOk()->assertJsonPath('result', 'updated');

    expect(Product::where('company_id', $id)->count())->toBe(1);
});

it('accepts logo and product image uploads (multipart only)', function () {
    Storage::fake('public');
    $key = agentKey();
    $id = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token']))->json('data.id');
    $pid = $this->postJson("/api/v1/agent/suppliers/{$id}/products", agentProductPayload(), agentHeaders($key['token']))->json('data.id');

    $this->post("/api/v1/agent/suppliers/{$id}/logo", ['image' => UploadedFile::fake()->image('logo.png')], agentHeaders($key['token']))
        ->assertCreated()->assertJsonPath('data.has_logo', true);
    $this->post("/api/v1/agent/products/{$pid}/image", ['image' => UploadedFile::fake()->image('p.jpg')], agentHeaders($key['token']))
        ->assertCreated()->assertJsonPath('data.has_image', true);
    $this->post("/api/v1/agent/products/{$pid}/image", ['image' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')], agentHeaders($key['token']))
        ->assertUnprocessable();
});

/* ------------------------------------------------------------- batch */

it('processes a batch with per-item results and partial failure', function () {
    $key = agentKey();
    $human = Company::factory()->create(['registration_number' => 'HUMAN-1']);

    $res = $this->postJson('/api/v1/agent/suppliers/batch', ['suppliers' => [
        agentSupplierPayload(['products' => [agentProductPayload(), agentProductPayload(['external_id' => 'bad', 'product_type' => 'nope'])]]),
        agentSupplierPayload(['external_id' => 'x-2', 'legal_name' => null, 'email' => null, 'website_url' => null]),
        agentSupplierPayload(['external_id' => 'x-3', 'legal_name' => 'Other Co', 'city' => 'Kribi', 'email' => null, 'website_url' => null, 'registration_number' => 'HUMAN-1']),
    ]], agentHeaders($key['token']))->assertOk();

    expect($res->json('data.0.result'))->toBe('created')
        ->and($res->json('data.0.products.0.result'))->toBe('created')
        ->and($res->json('data.0.products.1.result'))->toBe('rejected')
        ->and($res->json('data.0.products.1.errors'))->toHaveKey('product_type')
        ->and($res->json('data.1.result'))->toBe('rejected')
        ->and($res->json('data.1.errors'))->toHaveKey('legal_name')
        ->and($res->json('data.2.result'))->toBe('rejected')
        ->and($res->json('data.2.code'))->toBe('supplier_owned')
        ->and($res->json('data.2.supplier_id'))->toBe($human->id);

    expect(Company::where('source', 'agent:hermes')->count())->toBe(1)
        ->and(Product::where('source', 'agent:hermes')->count())->toBe(1);
});

it('writes nothing on a dry run batch', function () {
    $key = agentKey();

    $res = $this->postJson('/api/v1/agent/suppliers/batch', ['dry_run' => true, 'suppliers' => [
        agentSupplierPayload(['products' => [agentProductPayload()]]),
    ]], agentHeaders($key['token']))->assertOk();

    expect($res->json('meta.dry_run'))->toBeTrue()
        ->and($res->json('data.0.result'))->toBe('created')
        ->and($res->json('data.0.products.0.result'))->toBe('created')
        ->and(Company::count())->toBe(0)
        ->and(Product::count())->toBe(0);
});

/* ------------------------------------------------------- idempotency */

it('replays an Idempotency-Key response and rejects a conflicting payload', function () {
    $key = agentKey();
    $headers = agentHeaders($key['token'], ['Idempotency-Key' => 'abc-123']);

    $first = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), $headers)->assertCreated();

    $replay = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token'], ['Idempotency-Key' => 'abc-123']))
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');
    expect($replay->json())->toBe($first->json());

    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(['description' => 'different']), agentHeaders($key['token'], ['Idempotency-Key' => 'abc-123']))
        ->assertStatus(409)->assertJsonPath('error.code', 'idempotency_conflict');

    expect(Company::count())->toBe(1);

    $this->artisan('agent:prune-idempotency-keys')->assertSuccessful();
});

/* -------------------------------------------------------- moderation */

it('keeps agent data hidden until staff approve, verify and publish', function () {
    $key = agentKey();
    $id = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token']))->json('data.id');
    $this->postJson("/api/v1/agent/suppliers/{$id}/products", agentProductPayload(), agentHeaders($key['token']))->assertCreated();
    $company = Company::findOrFail($id);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(ListCompanies::class)
        ->filterTable('agent_submissions', true)
        ->assertCanSeeTableRecords([$company])
        ->callTableAction('agentApprove', $company);

    $company->refresh();
    expect($company->needs_review)->toBeFalse()
        ->and($company->status)->toBe(CompanyStatus::Pending)
        ->and($company->verificationRequests()->count())->toBe(1);

    // Pending companies cannot have products published.
    expect(fn () => app(ModerateAgentSubmission::class)->publishProducts($company, $admin))
        ->toThrow(\Illuminate\Validation\ValidationException::class);

    // Agent can no longer edit the profile once it is in review.
    $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token']))
        ->assertStatus(409)->assertJsonPath('error.code', 'supplier_locked');

    $company->forceFill(['status' => CompanyStatus::Verified])->save();
    expect(app(ModerateAgentSubmission::class)->publishProducts($company, $admin))->toBe(1)
        ->and($company->products()->first()->status)->toBe(ProductStatus::Active);
});

it('rejects (archives) an agent submission and supports the claim path', function () {
    $key = agentKey();
    $id = $this->postJson('/api/v1/agent/suppliers', agentSupplierPayload(), agentHeaders($key['token']))->json('data.id');
    $company = Company::findOrFail($id);
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $owner = User::factory()->create();
    app(ModerateAgentSubmission::class)->attachOwner($company, $owner->email, $admin);
    expect($company->fresh()->created_by)->toBe($owner->id);

    // Claimed → agents locked out.
    $this->postJson("/api/v1/agent/suppliers/{$id}/products", agentProductPayload(), agentHeaders($key['token']))
        ->assertStatus(409)->assertJsonPath('error.code', 'supplier_owned');

    app(ModerateAgentSubmission::class)->reject($company, $admin, 'spam');
    expect($company->fresh()->status)->toBe(CompanyStatus::Archived);
});
