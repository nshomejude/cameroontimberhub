<?php
// Renders the real transactional emails to standalone @dsCard preview files.
// Run with:  php artisan tinker design-system/_build/render-emails.php

@mkdir(base_path('design-system/emails'), 0777, true);

// --- RFQ verification (double opt-in for a quote request) ---
$rfq = new App\Models\Rfq();
$rfq->reference_code = 'RFQ-2025-0042';
$rfqHtml = (new App\Mail\RfqVerificationMail(
    $rfq,
    'https://cameroontimberhub.test/rfq/verify/EXAMPLE-TOKEN'
))->render();
file_put_contents(
    base_path('design-system/emails/rfq-verification.html'),
    '<!-- @dsCard group="Emails" name="RFQ verification email" subtitle="Double opt-in — confirm a quote request" -->'.PHP_EOL.$rfqHtml
);
echo 'rfq-verification.html: '.strlen($rfqHtml)." bytes\n";

// --- Inquiry verification (double opt-in for a message to an exporter) ---
$inquiry = new App\Models\CompanyInquiry();
$inqHtml = (new App\Mail\InquiryVerificationMail(
    $inquiry,
    'https://cameroontimberhub.test/inquiry/verify/EXAMPLE-TOKEN'
))->render();
file_put_contents(
    base_path('design-system/emails/inquiry-verification.html'),
    '<!-- @dsCard group="Emails" name="Inquiry verification email" subtitle="Double opt-in — confirm a message to an exporter" -->'.PHP_EOL.$inqHtml
);
echo 'inquiry-verification.html: '.strlen($inqHtml)." bytes\n";

echo "done\n";
