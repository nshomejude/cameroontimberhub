<?php

namespace App\Services;

use App\Models\SuspiciousEvent;

/**
 * Honeypot + minimum-submission-time check shared across all public intake
 * forms (RFQ, inquiry, contact). Rate-limiting is handled at the route level
 * with Laravel's `throttle` middleware; this service only inspects form fields.
 */
class AntiSpamService
{
    public function honeypotTripped(array $data, ?string $emailField = null): bool
    {
        $email = $data[$emailField ?? 'email'] ?? ($data['buyer_email'] ?? null);

        if (! empty($data['website'] ?? null)) {
            $this->log($email);

            return true;
        }

        $renderedAt = (int) ($data['form_rendered_at'] ?? 0);
        if ($renderedAt > 0 && (now()->timestamp - $renderedAt) < (int) config('trust.min_form_seconds', 3)) {
            $this->log($email);

            return true;
        }

        return false;
    }

    private function log(?string $email): void
    {
        SuspiciousEvent::record('honeypot_triggered', [
            'severity' => 'medium',
            'ip_address' => request()->ip(),
            'context' => ['email' => $email],
        ]);
    }
}
