<?php

namespace App\Enums;

/**
 * The closed set of things this platform records consent for. A `Consent`
 * row's `purpose` is always one of these, enforced by both this enum's cast
 * and the `consents_purpose_check` DB constraint (see the migration) so the
 * two cannot drift.
 *
 * Two cases exist today — the RFQ wizard's "share this request with
 * verified exporters and contact me by email" checkbox (see
 * `docs/superpowers/plans/2026-08-27-persisted-consent.md`, Task 3) and the
 * inquiry form's "share this inquiry with the company" checkbox (see
 * `docs/superpowers/plans/2026-08-28-inquiry-consent.md`). Add a case here
 * (and to the migration's CHECK constraint) whenever a new consent-bearing
 * flow is wired onto this table — e.g. the contact form's own consent
 * checkbox once its subject-storage question is resolved (see that plan's
 * "Scope decision"), or driver/vehicle telematics consent once §7 logistics
 * entities exist.
 */
enum ConsentPurpose: string
{
    case RfqExporterSharing = 'rfq_exporter_sharing';
    case CompanyInquirySharing = 'company_inquiry_sharing';

    public function label(): string
    {
        return match ($this) {
            self::RfqExporterSharing => 'RFQ shared with verified exporters',
            self::CompanyInquirySharing => 'Inquiry shared with the company',
        };
    }
}
