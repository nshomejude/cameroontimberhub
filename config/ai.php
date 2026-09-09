<?php

use App\Enums\AiProvider;
use App\Services\Ai\ClaudeProvider;

return [
    /*
    |--------------------------------------------------------------------
    | AI provider map
    |--------------------------------------------------------------------
    | provider value (App\Enums\AiProvider) => adapter class implementing
    | App\Contracts\AiProviderContract. API keys are NOT configured here —
    | they live encrypted in the ai_settings table, set only via the
    | two-person + step-up-2FA gated flow (App\Actions\Ai\*). Add a new
    | provider by adding a case to AiProvider and an adapter class here.
    |
    */
    'providers' => [
        AiProvider::Claude->value => ClaudeProvider::class,
    ],
];
