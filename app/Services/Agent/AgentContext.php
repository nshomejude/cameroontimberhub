<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\User;
use App\Support\Agent\AgentPrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who is writing, through which key, under which request id — carried into
 * every ingestion write for provenance columns and the activity log.
 */
final class AgentContext
{
    public function __construct(
        public readonly User $user,
        public readonly ?int $tokenId,
        public readonly string $source,
        public readonly ?string $requestId = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        /** @var User $user */
        $user = $request->user();

        return new self(
            user: $user,
            tokenId: $user->currentAccessToken()?->getKey(),
            source: AgentPrincipal::sourceFor(self::slugFor($user)),
            requestId: $request->attributes->get('request_id'),
        );
    }

    /** `agent+hermes@agents...` → `hermes`; falls back to the slugged user name. */
    public static function slugFor(User $user): string
    {
        if (preg_match('/^agent\+([a-z0-9-]+)@/i', (string) $user->email, $m) === 1) {
            return strtolower($m[1]);
        }

        return Str::slug((string) $user->name) ?: 'agent-'.$user->getKey();
    }

    /** @return array<string, mixed> */
    public function logProperties(array $extra = []): array
    {
        return array_merge([
            'token_id' => $this->tokenId,
            'request_id' => $this->requestId,
            'source' => $this->source,
        ], $extra);
    }
}
