<?php

return [

    // Days before a document's expiry_date that reminders fire (plus an
    // 'expired' threshold once it lapses). See RemindExpiringDocuments.
    'reminder_thresholds' => [90, 60, 30],

    // Which approved document_types.key(s) back each BadgeType. Empty = not
    // document-backed (e.g. premium_member is plan-gated, issued elsewhere).
    'badge_requirements' => [
        'verified_company' => ['business_registration'],
        'verified_exporter' => ['business_registration', 'export_permit'],
        'sigif_registered' => ['sigif_registration'],
        'legal_timber_supplier' => ['legality_certificate', 'forest_concession_title'],
        'export_ready' => ['export_permit', 'phytosanitary_certificate'],
        'cites_approved' => ['cites_permit'],
        'sustainability_profile' => ['legality_certificate'],
        'premium_member' => [],
    ],

    // Minutes a signed document download URL stays valid.
    'signed_url_ttl' => 5,
];
