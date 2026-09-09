<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Rfq;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI-assisted RFQ-to-supplier matching (blueprint §35). This is a purely
 * ADVISORY ranking layer on top of the real deterministic candidate query
 * (species/verification/leads-entitlement — the same facets used by
 * RfqTriageService::route() and Company's directory scopes). It never picks
 * the companies an RFQ actually gets routed to; it only re-orders and
 * annotates a shortlist for a staff member to review before they run
 * RfqTriageService::route() themselves.
 *
 * Degrades gracefully to the plain deterministic candidate list (DB order,
 * no AI ranking or "why" text) whenever AiGateway::isReady() is false or the
 * completion call throws/returns unusable output — AI availability must
 * never block or alter real RFQ routing.
 */
class RfqMatchingService
{
    public function __construct(private readonly AiGateway $ai) {}

    /**
     * @return Collection<int, array{company: Company, score: int|null, why: string|null}>
     */
    public function suggestSuppliers(Rfq $rfq, int $limit = 10): Collection
    {
        $candidates = $this->deterministicCandidates($rfq, $limit);

        if ($candidates->isEmpty()) {
            return $candidates->map(fn (Company $c) => ['company' => $c, 'score' => null, 'why' => null]);
        }

        if (! $this->ai->isReady()) {
            return $this->plainList($candidates);
        }

        try {
            return $this->rankWithAi($rfq, $candidates);
        } catch (Throwable $e) {
            Log::warning('RfqMatchingService: AI ranking failed, falling back to deterministic order', [
                'rfq_id' => $rfq->getKey(),
                'error' => $e->getMessage(),
            ]);

            return $this->plainList($candidates);
        }
    }

    /**
     * The REAL candidate query: verified companies handling the species
     * requested by the RFQ's items, that are actually entitled to receive
     * leads. Reuses Company::scopeHandlingSpecies() and hasFeature() —
     * the same facets RfqTriageService::route() enforces — rather than
     * reimplementing the matching rules here.
     */
    private function deterministicCandidates(Rfq $rfq, int $limit): Collection
    {
        $speciesSlugs = $rfq->items->pluck('species')
            ->filter()
            ->pluck('slug')
            ->unique()
            ->values()
            ->all();

        $query = Company::query()->where('status', 'verified');

        if ($speciesSlugs !== []) {
            $query->handlingSpecies($speciesSlugs);
        }

        if ($rfq->destination_country_code) {
            // Soft preference, not a hard filter: exporters headquartered in
            // the destination country still count as candidates if no
            // species match narrows things down, so this only orders.
            $query->orderByRaw('country_code = ? desc', [$rfq->destination_country_code]);
        }

        return $query->with(['species', 'verificationBadges'])
            ->orderBy('legal_name')
            ->get()
            ->filter(fn (Company $c) => $c->hasFeature('leads_receive'))
            ->take($limit)
            ->values();
    }

    /** @param Collection<int, Company> $candidates */
    private function plainList(Collection $candidates): Collection
    {
        return $candidates->map(fn (Company $c) => ['company' => $c, 'score' => null, 'why' => null]);
    }

    /** @param Collection<int, Company> $candidates */
    private function rankWithAi(Rfq $rfq, Collection $candidates): Collection
    {
        $system = <<<SYS
            You help a timber-trade platform's staff shortlist suppliers for a
            buyer's request for quote (RFQ). You do NOT make the routing
            decision — a human always reviews your suggestion before any RFQ
            is actually routed. Rank the given candidate suppliers (already
            pre-filtered by species/verification match) from most to least
            suitable, using only the facts given: capacity, verification
            tier, and any past fulfillment data. Do not invent facts not
            given.

            Respond with ONLY a JSON array, most suitable first, of objects:
            [{"company_id": <int>, "score": <0-100 integer>, "why": "<one short sentence>"}]
            No prose, no markdown fencing, no text outside the JSON array.
            SYS;

        $user = $this->buildUserPrompt($rfq, $candidates);

        $raw = $this->ai->driver()->complete($system, $user, ['max_tokens' => 1024]);

        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            Log::warning('RfqMatchingService: AI response was not valid JSON', ['raw' => $raw]);

            return $this->plainList($candidates);
        }

        $byId = $candidates->keyBy('id');
        $ranked = collect();

        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! isset($entry['company_id'])) {
                continue;
            }

            $company = $byId->get((int) $entry['company_id']);

            if (! $company) {
                continue;
            }

            $ranked->push([
                'company' => $company,
                'score' => isset($entry['score']) && is_numeric($entry['score']) ? (int) $entry['score'] : null,
                'why' => isset($entry['why']) && is_string($entry['why']) ? trim($entry['why']) : null,
            ]);
        }

        if ($ranked->isEmpty()) {
            Log::warning('RfqMatchingService: AI response contained no recognisable candidates', ['raw' => $raw]);

            return $this->plainList($candidates);
        }

        // Append any candidates the model omitted, at the end, un-ranked —
        // never let AI output silently drop a real deterministic candidate.
        $seenIds = $ranked->pluck('company.id')->all();
        foreach ($candidates as $company) {
            if (! in_array($company->id, $seenIds, true)) {
                $ranked->push(['company' => $company, 'score' => null, 'why' => null]);
            }
        }

        return $ranked->values();
    }

    /** @param Collection<int, Company> $candidates */
    private function buildUserPrompt(Rfq $rfq, Collection $candidates): string
    {
        $items = $rfq->items->map(fn ($i) => '- '.$i->label())->implode("\n");

        $lines = [
            "RFQ reference: {$rfq->reference_code}",
            'Requested items:',
            $items ?: '(no items listed)',
            'Destination country: '.($rfq->destination_country_code ?? 'unspecified'),
            'Target amount: '.($rfq->target_amount !== null ? (string) $rfq->target_amount : 'unspecified'),
            '',
            'Candidate suppliers:',
        ];

        foreach ($candidates as $company) {
            $tier = $company->verificationBadges->pluck('badge_type')
                ->map(fn ($t) => is_object($t) && method_exists($t, 'label') ? $t->label() : (string) $t)
                ->implode(', ') ?: 'none recorded';

            $capacity = $company->annual_capacity_m3 ?? $company->annual_harvest_capacity_m3;

            $lines[] = sprintf(
                '- id=%d | %s | region=%s, country=%s | annual capacity=%s m3 | verification badges=%s | species handled=%s',
                $company->id,
                $company->legal_name,
                $company->region ?? 'unspecified',
                $company->country_code ?? 'unspecified',
                $capacity !== null ? (string) $capacity : 'unspecified',
                $tier,
                $company->species->pluck('common_name')->implode(', ') ?: 'unspecified',
            );
        }

        return implode("\n", $lines);
    }
}
