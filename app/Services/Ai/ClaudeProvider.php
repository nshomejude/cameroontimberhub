<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderContract;
use App\Enums\AiProvider;
use App\Exceptions\AiProviderNotConfiguredException;
use App\Models\AiSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Claude adapter (App\Enums\AiProvider::Claude). Reads its API
 * key from the encrypted ai_settings row, never from .env — see
 * App\Actions\Ai for how that key is set. Uses the Messages API directly
 * over HTTP so no SDK dependency is required.
 */
class ClaudeProvider implements AiProviderContract
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    public function isConfigured(): bool
    {
        return AiSetting::forProvider(AiProvider::Claude)->hasKey();
    }

    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string
    {
        $setting = AiSetting::forProvider(AiProvider::Claude);
        $apiKey = $setting->decryptedApiKey();

        if (blank($apiKey)) {
            throw new AiProviderNotConfiguredException('claude');
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->timeout($options['timeout'] ?? 30)->post(self::API_URL, [
            'model' => $options['model'] ?? $setting->model ?? AiProvider::Claude->defaultModel(),
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('ClaudeProvider: API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('AI provider request failed (status '.$response->status().').');
        }

        return (string) ($response->json('content.0.text') ?? '');
    }

    public function extractStructured(string $documentText, array $schema): array
    {
        $fields = collect($schema)
            ->map(fn (string $description, string $field) => "- {$field}: {$description}")
            ->implode("\n");

        $system = <<<SYS
            You extract structured data from documents for a timber trade compliance platform.
            Extract ONLY the fields listed below, and ONLY when the document text actually
            supports the value — never guess, infer, or fabricate a value you cannot find
            evidence for in the text. Omit any field you cannot support from the output
            entirely (do not include it with an empty/null/placeholder value).
            Respond with nothing but a single JSON object mapping field name to extracted
            value. No prose, no markdown fencing.

            Fields to extract:
            {$fields}
            SYS;

        $raw = $this->complete($system, $documentText, ['max_tokens' => 1024]);

        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            Log::error('ClaudeProvider: extractStructured got non-JSON response', ['raw' => $raw]);

            return [];
        }

        // Defensive: only keep keys that were actually requested.
        return array_intersect_key($decoded, $schema);
    }
}
