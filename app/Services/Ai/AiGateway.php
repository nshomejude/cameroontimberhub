<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderContract;
use App\Enums\AiProvider;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Models\AiSetting;

/**
 * Resolves the currently-active AI provider adapter. Every AI feature
 * service (document extraction, RFQ matching, compliance assistant, fraud
 * enrichment) should depend on THIS class, never on a concrete provider
 * class directly — that's what lets a new provider be added later (e.g.
 * config/ai.php's provider map) without touching feature code.
 */
class AiGateway
{
    /** @var array<string, class-string<AiProviderContract>> */
    private array $providerMap;

    public function __construct()
    {
        $this->providerMap = config('ai.providers', [
            AiProvider::Claude->value => ClaudeProvider::class,
        ]);
    }

    public function driver(): AiProviderContract
    {
        $active = AiSetting::query()->where('is_active', true)->first();
        $providerValue = $active?->provider?->value ?? AiProvider::Claude->value;

        $class = $this->providerMap[$providerValue] ?? null;

        if (! $class) {
            throw new AiProviderNotConfiguredException($providerValue);
        }

        return app($class);
    }

    /** True only when the active provider has a real key set — features should check this before calling driver() to fail gracefully instead of throwing mid-workflow. */
    public function isReady(): bool
    {
        try {
            return $this->driver()->isConfigured();
        } catch (AiProviderNotConfiguredException) {
            return false;
        }
    }
}
