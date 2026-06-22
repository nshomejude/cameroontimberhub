<?php

namespace App\Services;

use App\Models\Rfq;
use App\Models\SuspiciousEvent;
use Illuminate\Support\Str;

/**
 * Computes spam_score / is_spam for an RFQ from additive heuristics and appends
 * suspicious_events rows. Heuristic flags live in event context, never on the
 * RFQ row (spec §4.4).
 */
class RfqRiskService
{
    /** @return list<string> */
    public function flags(Rfq $rfq): array
    {
        $flags = [];
        $domain = strtolower(Str::after($rfq->buyer_email, '@'));
        $maxQuantity = (float) $rfq->items->max('quantity');

        if (in_array($domain, (array) config('trust.disposable_email_domains'), true)) {
            $flags[] = 'disposable_email';
        }
        if (in_array($domain, (array) config('trust.free_email_domains'), true) && $maxQuantity >= (float) config('trust.free_email_volume_threshold')) {
            $flags[] = 'free_email_high_volume';
        }
        if (preg_match('~https?://|www\.~i', (string) $rfq->notes)) {
            $flags[] = 'link_in_message';
        }
        if ($maxQuantity >= (float) config('trust.oversized_quantity')) {
            $flags[] = 'oversized_quantity';
        }
        if ($rfq->ip_address && Rfq::where('ip_address', $rfq->ip_address)->whereKeyNot($rfq->getKey())->where('created_at', '>=', now()->subMinutes(10))->count() >= 3) {
            $flags[] = 'burst_ip';
        }
        if (Rfq::where('buyer_email', $rfq->buyer_email)->whereKeyNot($rfq->getKey())->where('created_at', '>=', now()->subDay())->exists()) {
            $flags[] = 'duplicate_recent';
        }

        return $flags;
    }

    public function evaluate(Rfq $rfq): void
    {
        $weights = (array) config('trust.weights');
        $flags = $this->flags($rfq);

        $score = collect($flags)->sum(fn (string $flag): int => (int) ($weights[$flag] ?? 0));
        $score = min(100, $score);
        $isSpam = $score >= (int) config('trust.autospam_threshold');

        $rfq->update(['spam_score' => $score, 'is_spam' => $isSpam]);

        $context = ['buyer_email' => $rfq->buyer_email, 'flags' => $flags, 'score' => $score];
        $base = ['subject_type' => Rfq::class, 'subject_id' => $rfq->getKey(), 'ip_address' => $rfq->ip_address, 'context' => $context];

        if (in_array('burst_ip', $flags, true)) {
            SuspiciousEvent::record('rapid_rfq_burst', array_merge($base, ['severity' => 'medium']));
        }
        if (in_array('duplicate_recent', $flags, true)) {
            SuspiciousEvent::record('duplicate_submission', array_merge($base, ['severity' => 'medium']));
        }
        if ($isSpam) {
            SuspiciousEvent::record('suspicious_rfq', array_merge($base, ['severity' => 'high']));
        }
    }
}
