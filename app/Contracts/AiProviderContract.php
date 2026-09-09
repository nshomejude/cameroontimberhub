<?php

namespace App\Contracts;

/**
 * One implementation per AI provider (App\Enums\AiProvider — starting with
 * Claude/Anthropic). Each lives under app/Services/Ai/{Provider}Provider.php
 * and is resolved by App\Services\AiGateway via the row currently marked
 * active in the ai_settings table (never a hardcoded class).
 *
 * The API key is NOT read from .env — it lives encrypted in the ai_settings
 * table, set/changed only via the two-person + step-up-2FA gated flow in
 * App\Actions\Ai\{Request,Approve}AiApiKeyChange (blueprint §39/§88-89
 * pattern, mirroring VerificationRevocationRequest/CompanySuspensionRequest).
 *
 * No implementation may call out to a real API until isConfigured() is
 * true — a missing/blank key must make every method throw
 * App\Exceptions\AiProviderNotConfiguredException rather than silently
 * returning fabricated data.
 */
interface AiProviderContract
{
    /**
     * A single-turn text completion: system instructions + user content in,
     * plain text out. The workhorse for RFQ matching, fraud-signal
     * enrichment, and the compliance assistant's answers.
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): string;

    /**
     * Extracts structured fields from unstructured document text against an
     * explicit JSON schema (associative array of field => description).
     * Must return ONLY fields the model actually found evidence for in the
     * text — omit (never guess/hallucinate) a field it can't support, and
     * every implementation must document this requirement to the model in
     * its own prompt.
     *
     * @param  array<string, string>  $schema  field name => description of what to extract
     * @return array<string, mixed> extracted field => value (missing fields simply absent)
     */
    public function extractStructured(string $documentText, array $schema): array;

    /** True only when this provider's real API key is present and non-blank in ai_settings — never a fake/placeholder check. */
    public function isConfigured(): bool;
}
