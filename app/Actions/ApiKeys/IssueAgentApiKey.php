<?php

declare(strict_types=1);

namespace App\Actions\ApiKeys;

use App\Models\ApiKeyMeta;
use App\Models\User;
use App\Support\Agent\AgentPrincipal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;

/**
 * Mint a machine-principal ("agent") API key — the Agent Ingestion Gateway's
 * counterpart of ApproveApiKeyIssuance. Unlike company keys, an agent key is
 * NOT held by a company owner: it lives on a dedicated service-account user
 * (`agent+{slug}@agents.cameroontimberhub.local`, random unusable password,
 * role `agent`, no company_user rows, so no panel access) and is only usable
 * on `/api/v1/agent/*`. Issued from the CLI (`agent:create-key`), which is
 * itself a privileged-operator surface, hence no two-person flow here.
 */
class IssueAgentApiKey
{
    /**
     * @param  list<string>  $abilities
     * @return array{plain_text_token: string, meta: ApiKeyMeta, user: User}
     */
    public function execute(string $name, array $abilities, ?int $expiresInDays = 365): array
    {
        $slug = Str::slug($name);

        if ($slug === '') {
            throw new InvalidArgumentException('Agent name must contain at least one letter or digit.');
        }

        $unknown = array_diff($abilities, array_keys(AgentPrincipal::ABILITIES));
        if ($abilities === [] || $unknown !== []) {
            throw new InvalidArgumentException('Unknown agent abilities: '.implode(', ', $unknown ?: ['(none given)']));
        }

        return DB::transaction(function () use ($name, $slug, $abilities, $expiresInDays): array {
            Role::findOrCreate(AgentPrincipal::ROLE, 'web');

            $email = 'agent+'.$slug.'@'.AgentPrincipal::EMAIL_DOMAIN;

            $user = User::query()->firstWhere('email', $email);

            if ($user === null) {
                $user = new User;
                $user->forceFill([
                    'name' => $name,
                    'email' => $email,
                    // Never used to log in: random, unknown to anyone.
                    'password' => Hash::make(Str::random(64)),
                ])->save();
            }

            if ($user->companies()->exists()) {
                throw new InvalidArgumentException("User {$email} belongs to a company and cannot be an agent principal.");
            }

            $user->assignRole(AgentPrincipal::ROLE);

            $newToken = $user->createToken(
                $name,
                array_values($abilities),
                $expiresInDays !== null && $expiresInDays > 0 ? now()->addDays($expiresInDays) : null,
            );

            $meta = ApiKeyMeta::create([
                'personal_access_token_id' => $newToken->accessToken->getKey(),
                'kind' => AgentPrincipal::KEY_KIND,
                'company_id' => null,
                'rate_limit_tier' => 'elevated',
                'requested_by' => null,
                'approved_by' => null,
            ]);

            activity('agent-ingestion')
                ->performedOn($user)
                ->event('agent_key_issued')
                ->withProperties(['token_id' => $newToken->accessToken->getKey(), 'abilities' => $abilities])
                ->log("Agent API key issued for {$email}");

            return ['plain_text_token' => $newToken->plainTextToken, 'meta' => $meta, 'user' => $user];
        });
    }
}
