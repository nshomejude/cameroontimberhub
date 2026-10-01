<?php

namespace App\Services;

/**
 * Outcome of RfqTriageService::routeDetailed(): how many NEW routings were
 * created, and every requested company that was not routed, with why — so the
 * admin UI can warn instead of showing "Routed to 0" as a success.
 */
final class RfqRoutingResult
{
    public const REASON_NOT_FOUND = 'not_found';

    public const REASON_NO_ENTITLEMENT = 'no_leads_receive';

    public const REASON_ALREADY_ROUTED = 'already_routed';

    public const REASON_CONSENT_REVOKED = 'consent_revoked';

    /**
     * @param  list<array{company_id: int, company: ?string, reason: string}>  $skipped
     */
    public function __construct(
        public readonly int $routed,
        public readonly array $skipped = [],
    ) {}

    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_NOT_FOUND => 'company not found',
            self::REASON_NO_ENTITLEMENT => 'plan lacks lead delivery (leads_receive)',
            self::REASON_ALREADY_ROUTED => 'already routed',
            self::REASON_CONSENT_REVOKED => 'buyer revoked sharing consent',
            default => $reason,
        };
    }

    /** One human line per skipped company, for an admin notification body. */
    public function skippedSummary(): string
    {
        return collect($this->skipped)
            ->map(fn (array $s) => ($s['company'] ?? "#{$s['company_id']}").' — '.self::reasonLabel($s['reason']))
            ->implode("; ");
    }
}
