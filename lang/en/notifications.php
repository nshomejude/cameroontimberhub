<?php

// User-facing copy for transactional emails (app/Mail/*) and notifications
// (app/Notifications/*). Extracted in Batch D4 of the production-readiness plan.
//
// Locale: notifications sent to a User currently render in config('app.locale')
// because there is no users.locale column yet.
// TODO: per-recipient locale once users.locale exists.

return [

    // ----- app/Mail/RfqVerificationMail + emails/rfq-verification.blade.php -----
    'rfq_verification' => [
        'subject' => 'Confirm your quote request — :reference',
        'heading' => 'Confirm your request',
        'intro' => 'Thanks for your request :reference. Please confirm your email so we can route it to verified Cameroonian timber exporters.',
        'action' => 'Confirm request',
        'disclaimer' => 'Submitting a request does not constitute a contract. Buyers should conduct final due diligence before any transaction.',
        'salutation' => 'Thanks,',
    ],

    // ----- app/Mail/InquiryVerificationMail + emails/inquiry-verification.blade.php -----
    'inquiry_verification' => [
        'subject' => 'Confirm your inquiry — Cameroon Timber Hub',
        'heading' => 'Confirm your inquiry',
        'intro' => 'Please confirm your email so your message can be delivered to the exporter.',
        'action' => 'Confirm inquiry',
        'disclaimer' => 'Buyers should conduct final due diligence before any transaction.',
        'salutation' => 'Thanks,',
    ],

    // ----- app/Mail/QuoteSubmittedMail + emails/quote-submitted.blade.php -----
    'quote_submitted' => [
        'subject' => 'New quote for :reference — :company',
        'heading' => 'You have a new quote',
        'intro' => ':company has responded to your request :reference.',
        'quote_reference' => 'Quote reference:',
        'total' => 'Total:',
        'lead_time' => 'Lead time:',
        'lead_time_value' => ':days days',
        'valid_until' => 'Valid until:',
        'action' => 'Review your quotes',
        'personal_link' => 'This link is personal to your request — please do not forward it.',
        'disclaimer' => 'Receiving a quote does not constitute a contract. Buyers should conduct final due diligence before any transaction.',
        'salutation' => 'Thanks,',
    ],

    // ----- app/Mail/ContactMessageMail + emails/contact-message.blade.php -----
    // Internal team mailbox message.
    'contact_message' => [
        'subject' => '[CTH Contact] :subject',
        'heading' => 'New contact form message',
        'name' => 'Name:',
        'company' => 'Company:',
        'email' => 'Email:',
        'phone' => 'Phone:',
        'subject_label' => 'Subject:',
        'reply_hint' => 'Reply directly to this email to answer the sender.',
    ],

    // ----- app/Mail/ErrorDigestMail — internal ops digest -----
    // The digest body is rendered server-side in English (ops-only) and is
    // intentionally not translated.
    'error_digest' => [
        'subject' => '[:app] Error digest — :count error(s) in the last window',
    ],

    // ----- app/Notifications/CompanyVerifiedNotification -----
    'company_verified' => [
        'subject' => 'Your company has been verified — :company',
        'line_1' => 'Congratulations! :company has been verified by the Cameroon Timber Hub team.',
        'line_2' => 'Your verified badge is now live on the public directory.',
        'action' => 'View your dashboard',
        'line_3' => 'Documents reviewed by Cameroon Timber Hub based on information submitted by the company. Buyers should conduct final due diligence before any transaction.',
    ],

    // ----- app/Notifications/DocumentExpiring -----
    'document_expiring' => [
        'subject' => 'Compliance document expiry — :company',
        'fallback_type' => 'compliance document',
        'expired_line_1' => 'Your :type has expired.',
        'expired_line_2' => 'Please upload a current document to keep your verified listing active.',
        'expiring_line_1' => 'Your :type expires in :days days (on :date).',
        'expiring_line_2' => 'Please renew it before it lapses to keep your verified listing.',
        'action' => 'Manage documents',
    ],

    // ----- app/Notifications/RfqRoutedToExporter -----
    'rfq_routed_to_exporter' => [
        'subject' => 'New buyer lead — :reference',
        'line_1' => 'A verified buyer request has been routed to your company.',
        'action' => 'View in your dashboard',
        'line_2' => 'Reference: :reference',
    ],

];
