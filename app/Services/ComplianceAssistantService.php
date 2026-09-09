<?php

namespace App\Services;

use App\Models\ComplianceAssistantQuery;
use App\Models\ComplianceRule;
use App\Models\RegulatorySource;
use App\Models\User;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Blueprint §35 AI Compliance Assistant: grounded question-answering for
 * compliance officers (e.g. "does a shipment of Sapele sawn timber to
 * France need a FLEGT license under current rules?").
 *
 * This is the HIGHEST-STAKES AI feature on the platform — a wrong answer
 * has real legal/reputational consequences for suppliers — so it is
 * deliberately NOT a free-floating chat. Every answer is built by:
 *   1. Retrieving REAL ComplianceRule/RegulatorySource rows relevant to the
 *      question via plain keyword/field matching against country_code,
 *      product_category and supplier_type (see retrieve()) — no vector DB.
 *   2. Passing ONLY that retrieved real content to the model as context,
 *      under a system prompt that forbids answering beyond it.
 *   3. Recording exactly which rows were used, so the UI can show its
 *      grounding and a human can audit the answer later.
 *
 * Every response carries a legal disclaimer and, when nothing relevant is
 * on file, the assistant says so rather than letting the model guess.
 */
class ComplianceAssistantService
{
    public const DISCLAIMER = 'This answer is generated from the compliance rules and regulatory sources on file and is not legal advice. It does not replace review by qualified compliance staff before any trade decision is made.';

    /** Simple per-user abuse guard: no more than this many questions per rolling minute. */
    private const MAX_QUERIES_PER_MINUTE = 5;

    public function __construct(private readonly AiGateway $gateway) {}

    /**
     * @return array{answer: string, grounding: Collection, disclaimer: string, rate_limited: bool, ai_ready: bool}
     */
    public function ask(string $question, ?string $countryCode = null, ?string $productCategory = null, ?User $askedBy = null): array
    {
        $askedBy ??= auth()->user();

        if ($askedBy && $this->isRateLimited($askedBy)) {
            return [
                'answer' => 'You have asked several questions in the last minute. Please wait a moment before asking another to keep this tool available for everyone.',
                'grounding' => collect(),
                'disclaimer' => self::DISCLAIMER,
                'rate_limited' => true,
                'ai_ready' => true,
            ];
        }

        $rules = $this->retrieveRules($question, $countryCode, $productCategory);
        $sources = $this->retrieveSources($question, $rules);
        $grounding = $rules->concat($sources);

        if (! $this->gateway->isReady()) {
            $answer = 'The AI compliance assistant is not currently configured (no active AI provider key on file). '
                .'Please consult the compliance rules and regulatory sources directly, or ask a platform administrator to configure the AI provider.';

            $this->logQuery($askedBy, $question, $countryCode, $productCategory, $rules, $sources, $answer, wasGrounded: false, aiWasReady: false);

            return [
                'answer' => $answer,
                'grounding' => $grounding,
                'disclaimer' => self::DISCLAIMER,
                'rate_limited' => false,
                'ai_ready' => false,
            ];
        }

        if ($rules->isEmpty() && $sources->isEmpty()) {
            $answer = 'There is not enough regulatory information on file to answer this question with confidence. '
                .'No matching compliance rule or regulatory source was found for the country, product, or supplier details given. '
                .'Please have a qualified compliance officer review this question directly rather than relying on an AI-generated answer.';

            $this->logQuery($askedBy, $question, $countryCode, $productCategory, $rules, $sources, $answer, wasGrounded: false, aiWasReady: true);

            return [
                'answer' => $answer,
                'grounding' => $grounding,
                'disclaimer' => self::DISCLAIMER,
                'rate_limited' => false,
                'ai_ready' => true,
            ];
        }

        $answer = $this->generateGroundedAnswer($question, $rules, $sources);

        $this->logQuery($askedBy, $question, $countryCode, $productCategory, $rules, $sources, $answer, wasGrounded: true, aiWasReady: true);

        return [
            'answer' => $answer,
            'grounding' => $grounding,
            'disclaimer' => self::DISCLAIMER,
            'rate_limited' => false,
            'ai_ready' => true,
        ];
    }

    /**
     * Keyword/field retrieval against real ComplianceRule rows: matches on
     * country_code, product_category and supplier_type when given
     * explicitly, and falls back to scanning regulatory_framework /
     * decision_rules / commodity_code / product_category text for keywords
     * pulled out of the free-text question (e.g. "sapele", "flegt",
     * "france"). Wildcard rows (null dimension) are naturally included by
     * ComplianceRule::scopeApplicableTo() when a country is known.
     */
    private function retrieveRules(string $question, ?string $countryCode, ?string $productCategory): Collection
    {
        $keywords = $this->extractKeywords($question);

        $query = ComplianceRule::query()->where('is_active', true)->with('regulatorySource');

        if ($countryCode) {
            $query->applicableTo($countryCode, $productCategory);
        } else {
            $query->where(function ($q) use ($keywords, $productCategory) {
                if ($productCategory) {
                    $q->orWhere('product_category', $productCategory);
                }

                foreach ($keywords as $keyword) {
                    $q->orWhere('regulatory_framework', 'ilike', "%{$keyword}%")
                        ->orWhere('product_category', 'ilike', "%{$keyword}%")
                        ->orWhere('commodity_code', 'ilike', "%{$keyword}%")
                        ->orWhere('decision_rules', 'ilike', "%{$keyword}%")
                        ->orWhere('country_code', 'ilike', "%{$keyword}%")
                        ->orWhere('market', 'ilike', "%{$keyword}%");
                }
            });
        }

        return $query->limit(10)->get();
    }

    /**
     * Regulatory sources are pulled two ways: those already linked to a
     * retrieved rule (guaranteed real grounding for that rule) plus any
     * additional source whose jurisdiction/instrument_name/summary matches
     * a keyword from the question, in case a directly relevant source
     * exists without (yet) a rule row attached.
     */
    private function retrieveSources(string $question, Collection $rules): Collection
    {
        $fromRules = $rules->pluck('regulatorySource')->filter()->unique('id');

        $keywords = $this->extractKeywords($question);

        $keywordMatches = RegulatorySource::query()
            ->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    $q->orWhere('instrument_name', 'ilike', "%{$keyword}%")
                        ->orWhere('jurisdiction', 'ilike', "%{$keyword}%")
                        ->orWhere('summary', 'ilike', "%{$keyword}%");
                }
            })
            ->limit(5)
            ->get();

        return $fromRules->concat($keywordMatches)->unique('id')->values();
    }

    /** Splits the question into lowercase word-ish tokens of 3+ chars, so a query can match real column content without any external NLP. */
    private function extractKeywords(string $question): array
    {
        preg_match_all('/[a-zA-Z]{3,}/', $question, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $word) => strtolower($word))
            ->unique()
            ->values()
            ->all();
    }

    private function generateGroundedAnswer(string $question, Collection $rules, Collection $sources): string
    {
        $context = $this->buildContext($rules, $sources);

        $system = <<<SYS
            You are a compliance research aid for a B2B timber trade platform. Answer
            the compliance officer's question using ONLY the regulatory context
            provided below — never use outside knowledge, never guess, and never
            invent a rule, source, or legal conclusion that is not directly
            supported by the context given.

            If the provided context does not contain enough information to answer
            the question with confidence, say so explicitly and name what
            additional information or source would be needed, rather than
            speculating.

            Always end your answer by referencing which rule(s)/source(s) from the
            context you relied on.

            Regulatory context:
            {$context}
            SYS;

        try {
            return trim($this->gateway->driver()->complete($system, $question, ['max_tokens' => 800]));
        } catch (\Throwable $e) {
            Log::error('ComplianceAssistantService: AI completion failed', ['error' => $e->getMessage()]);

            return 'The AI provider could not be reached to generate an answer. The regulatory context below was retrieved and matches your question, but please review it directly or try again shortly.';
        }
    }

    private function buildContext(Collection $rules, Collection $sources): string
    {
        $ruleLines = $rules->map(function (ComplianceRule $rule) {
            return "- Rule #{$rule->id} [{$rule->regulatory_framework}] country=".($rule->country_code ?? 'any')
                .' product='.($rule->product_category ?? 'any')
                .' supplier_type='.($rule->supplier_type ?? 'any')
                .' required_evidence='.json_encode($rule->required_evidence)
                .' decision_rules='.($rule->decision_rules ?? 'n/a')
                .' effective_date='.($rule->effective_date?->toDateString() ?? 'n/a');
        });

        $sourceLines = $sources->map(function (RegulatorySource $source) {
            return "- Source #{$source->id} [{$source->instrument_name}] authority={$source->authority} jurisdiction={$source->jurisdiction}"
                .' summary='.($source->summary ?? 'n/a')
                .' effective_date='.($source->effective_date?->toDateString() ?? 'n/a');
        });

        return "Compliance rules:\n".($ruleLines->implode("\n") ?: 'none')
            ."\n\nRegulatory sources:\n".($sourceLines->implode("\n") ?: 'none');
    }

    private function logQuery(
        ?User $askedBy,
        string $question,
        ?string $countryCode,
        ?string $productCategory,
        Collection $rules,
        Collection $sources,
        string $answer,
        bool $wasGrounded,
        bool $aiWasReady,
    ): void {
        if (! $askedBy) {
            return;
        }

        ComplianceAssistantQuery::query()->create([
            'asked_by' => $askedBy->id,
            'question' => $question,
            'country_code' => $countryCode,
            'product_category' => $productCategory,
            'grounding_rule_ids' => $rules->pluck('id')->values()->all(),
            'grounding_source_ids' => $sources->pluck('id')->values()->all(),
            'answer' => $answer,
            'was_grounded' => $wasGrounded,
            'ai_was_ready' => $aiWasReady,
        ]);
    }

    private function isRateLimited(User $user): bool
    {
        return ComplianceAssistantQuery::query()
            ->where('asked_by', $user->id)
            ->where('created_at', '>=', now()->subMinute())
            ->count() >= self::MAX_QUERIES_PER_MINUTE;
    }
}
