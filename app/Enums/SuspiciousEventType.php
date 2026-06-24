<?php

namespace App\Enums;

enum SuspiciousEventType: string
{
    case HoneypotTriggered  = 'honeypot_triggered';
    case RapidRfqBurst      = 'rapid_rfq_burst';
    case DuplicateSubmission = 'duplicate_submission';
    case SuspiciousRfq      = 'suspicious_rfq';
    case RateLimited        = 'rate_limited';

    public function label(): string
    {
        return match ($this) {
            self::HoneypotTriggered   => 'Honeypot triggered',
            self::RapidRfqBurst       => 'Rapid RFQ burst',
            self::DuplicateSubmission => 'Duplicate submission',
            self::SuspiciousRfq       => 'Suspicious RFQ',
            self::RateLimited         => 'Rate limited',
        };
    }
}
