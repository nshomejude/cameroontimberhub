<?php

use App\Actions\ApiKeys\IssueAgentApiKey;
use App\Models\ApiKeyMeta;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * EnforceApiKeyPolicy must not add a second token lookup on top of
 * auth:sanctum, nor load roles for non-agent keys.
 */

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function policyQueries(callable $fn): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $log = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    return [
        'tokens' => $log->filter(fn ($q) => str_starts_with($q, 'select') && str_contains($q, 'from "personal_access_tokens"'))->count(),
        'metas' => $log->filter(fn ($q) => str_contains($q, 'from "api_key_metas"'))->count(),
    ];
}

it('looks the bearer token up once per request', function () {
    $user = User::factory()->create();
    $token = $user->createToken('device')->plainTextToken;
    app('auth')->forgetGuards();

    $counts = policyQueries(fn () => $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk());

    expect($counts['tokens'])->toBe(1)->and($counts['metas'])->toBe(1);
});

it('still confines agent keys and rejects revoked keys', function () {
    $r = app(IssueAgentApiKey::class)->execute('Hermes', ['agent:ingest', 'agent:read'], 365);
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/rfqs', ['Authorization' => 'Bearer '.$r['plain_text_token']])
        ->assertForbidden()->assertJsonPath('error.code', 'agent_scope_violation');

    $user = User::factory()->create();
    $token = $user->createToken('device');
    ApiKeyMeta::create(['personal_access_token_id' => $token->accessToken->id, 'kind' => 'company', 'revoked_at' => now()]);
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer '.$token->plainTextToken])
        ->assertUnauthorized()->assertJsonPath('error.code', 'token_revoked');
});
