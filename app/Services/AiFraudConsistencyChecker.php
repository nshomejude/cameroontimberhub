<?php

namespace App\Services;

use App\Models\Company;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI-assisted cross-field consistency checking on company registration /
 * onboarding data (blueprint §25/§35): does the stated registration number,
 * country and company name look internally plausible, and does the free-text
 * description contradict other declared fields (e.g. claims to be a
 * "certified organic plantation" with no supporting documents, or described
 * product categories that don't match the company type)?
 *
 * SOFT SIGNAL ONLY. This never auto-rejects, auto-verifies, or auto-flags a
 * company as fraudulent on its own -- it only ever proposes a FraudSignal for
 * a human admin to review, and only when the model actually reports a
 * genuine inconsistency. It must never manufacture a signal to seem useful:
 * check() returns null whenever nothing suspicious is found.
 *
 * Entirely best-effort: the AI call is optional. When AiGateway::isReady()
 * is false (no provider key configured -- the default state) or the
 * underlying call fails for any reason, check() returns null rather than
 * throwing, so this can never become a hard dependency of company creation.
 */
class AiFraudConsistencyChecker
{
    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array{summary: string, severity: string, reasoning: string}|null
     */
    public function check(Company $company): ?array
    {
        if (! $this->gateway->isReady()) {
            return null;
        }

        try {
            $response = $this->gateway->driver()->complete(
                $this->systemPrompt(),
                $this->userPrompt($company),
            );

            return $this->parseResponse($response);
        } catch (Throwable $e) {
            Log::warning('AiFraudConsistencyChecker failed to run', [
                'company_id' => $company->id ?? null,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are a fraud-review assistant for a B2B timber marketplace. You
            are given a company's declared onboarding fields. Look ONLY for
            genuine internal inconsistencies between the declared fields --
            e.g. a registration number format that does not plausibly match
            the declared country, a free-text description that contradicts
            the declared company type or product categories, or claims (such
            as "certified organic plantation") that are not supported by
            anything else declared.

            This is a soft signal for human review only -- never a
            verification or rejection decision. Do not invent problems: if
            the fields look plausible and consistent, say so.

            Respond with ONLY a JSON object, no other text, in this exact
            shape:
            {"inconsistent": true|false, "severity": "low"|"medium"|"high", "summary": "short phrase", "reasoning": "one or two sentences"}

            Set "inconsistent" to false (and omit any fabricated summary)
            whenever nothing genuinely suspicious stands out.
            PROMPT;
    }

    private function userPrompt(Company $company): string
    {
        $categories = method_exists($company, 'products')
            ? $company->products()->pluck('category_id')->unique()->filter()->values()->all()
            : [];

        $fields = [
            'company_name' => $company->name,
            'legal_name' => $company->legal_name,
            'trade_name' => $company->trade_name,
            'registration_number' => $company->registration_number,
            'country_code' => $company->country_code,
            'organisation_type' => $company->type?->value,
            'supplier_type' => $company->supplier_type?->value,
            'description' => $company->description,
            'product_category_ids' => $categories,
        ];

        return "Declared company fields (JSON):\n".json_encode($fields, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{summary: string, severity: string, reasoning: string}|null
     */
    private function parseResponse(string $response): ?array
    {
        $json = json_decode(trim($response), true);

        if (! is_array($json)) {
            return null;
        }

        if (empty($json['inconsistent'])) {
            return null;
        }

        $severity = in_array($json['severity'] ?? null, ['low', 'medium', 'high'], true)
            ? $json['severity']
            : 'low';

        return [
            'summary' => (string) ($json['summary'] ?? 'AI-flagged inconsistency'),
            'severity' => $severity,
            'reasoning' => (string) ($json['reasoning'] ?? ''),
        ];
    }
}
