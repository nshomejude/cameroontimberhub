<?php

declare(strict_types=1);

namespace App\Support\Agent;

/**
 * Constants shared by the Agent Ingestion Gateway (docs/api/AGENT_INGESTION.md).
 *
 * An "agent" is a machine principal (e.g. Hermes): a dedicated service-account
 * User holding the `agent` role, with NO company_user rows and no panel access,
 * whose Sanctum token carries the `agent:*` abilities below. Agent tokens are
 * confined to `/api/v1/agent/*` by App\Http\Middleware\EnforceApiKeyPolicy.
 */
final class AgentPrincipal
{
    /** Spatie role every service-account agent user holds. */
    public const ROLE = 'agent';

    /** `api_key_metas.kind` value for agent keys. */
    public const KEY_KIND = 'agent';

    public const ABILITY_INGEST = 'agent:ingest';

    public const ABILITY_READ = 'agent:read';

    /** @var array<string, string> ability => label */
    public const ABILITIES = [
        self::ABILITY_INGEST => 'Create/update agent-sourced suppliers and products',
        self::ABILITY_READ => 'Read reference data and ingestion status',
    ];

    /** Email domain for service-account users (never deliverable). */
    public const EMAIL_DOMAIN = 'agents.cameroontimberhub.local';

    /** Prefix of `companies.source` / `products.source` for agent-written rows. */
    public const SOURCE_PREFIX = 'agent:';

    public static function sourceFor(string $agentSlug): string
    {
        return self::SOURCE_PREFIX.$agentSlug;
    }

    public static function isAgentSource(?string $source): bool
    {
        return $source !== null && str_starts_with($source, self::SOURCE_PREFIX);
    }
}
