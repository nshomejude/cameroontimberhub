<?php

namespace App\Enums;

/**
 * Lifecycle of a formal Dispute (blueprint §64): Opened -> Evidence
 * Submission -> Counterparty Response -> Admin Decision -> optional Appeal
 * -> Closed.
 *
 * Mirrors the `disputes_status_check` CHECK constraint exactly.
 */
enum DisputeStatus: string
{
    case Opened = 'opened';
    case EvidencePending = 'evidence_pending';
    case CounterpartyResponsePending = 'counterparty_response_pending';
    case UnderReview = 'under_review';
    case Resolved = 'resolved';
    case Appealed = 'appealed';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Opened',
            self::EvidencePending => 'Evidence submission',
            self::CounterpartyResponsePending => 'Counterparty response',
            self::UnderReview => 'Under review',
            self::Resolved => 'Resolved',
            self::Appealed => 'Appealed',
            self::Closed => 'Closed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Opened, self::EvidencePending, self::CounterpartyResponsePending => 'warning',
            self::UnderReview => 'info',
            self::Resolved => 'success',
            self::Appealed => 'danger',
            self::Closed => 'gray',
        };
    }

    /** True once the dispute can no longer change. */
    public function isTerminal(): bool
    {
        return $this === self::Closed;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
