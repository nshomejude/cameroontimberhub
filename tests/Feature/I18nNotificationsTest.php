<?php

use App\Mail\ContactMessageMail;
use App\Mail\ErrorDigestMail;
use App\Mail\InquiryVerificationMail;
use App\Mail\QuoteSubmittedMail;
use App\Mail\RfqVerificationMail;
use App\Models\Company;
use App\Models\CompanyDocument;
use App\Models\CompanyInquiry;
use App\Models\DocumentType;
use App\Models\Quote;
use App\Models\Rfq;
use App\Models\User;
use App\Notifications\CompanyVerifiedNotification;
use App\Notifications\DocumentExpiring;
use App\Notifications\RfqRoutedToExporter;
use Illuminate\Support\Facades\App;

/**
 * Batch D / Task D4 — transactional emails + notifications i18n.
 *
 * Each mailable / notification must render its subject and body in both locales
 * from lang/{en,fr}/notifications.php, and must never leak a raw `notifications.`
 * translation key.
 */

function assertNoRawKey(string $haystack): void
{
    expect($haystack)->not->toContain('notifications.');
}

function renderMailable(\Illuminate\Mail\Mailable $mailable): array
{
    return [$mailable->envelope()->subject, (string) $mailable->render()];
}

function renderNotificationMail($notification, $notifiable): array
{
    $mail = $notification->toMail($notifiable);
    $lines = implode(' ', array_merge($mail->introLines, $mail->outroLines, [$mail->actionText ?? '']));

    return [$mail->subject, $lines];
}

it('renders the RFQ verification email in both locales', function (string $locale, string $subjectNeedle, string $bodyNeedle) {
    App::setLocale($locale);
    $rfq = Rfq::factory()->create(['reference_code' => 'RFQ-2026-ABCDE']);

    [$subject, $body] = renderMailable(new RfqVerificationMail($rfq, 'https://example.test/verify'));

    expect($subject)->toContain($subjectNeedle);
    expect($body)->toContain($bodyNeedle);
    assertNoRawKey($subject.$body);
})->with([
    ['en', 'Confirm your quote request', 'Confirm your request'],
    ['fr', 'Confirmez votre demande de devis', 'Confirmez votre demande'],
]);

it('renders the inquiry verification email in both locales', function (string $locale, string $subjectNeedle, string $bodyNeedle) {
    App::setLocale($locale);
    $inquiry = CompanyInquiry::factory()->create();

    [$subject, $body] = renderMailable(new InquiryVerificationMail($inquiry, 'https://example.test/verify'));

    expect($subject)->toContain($subjectNeedle);
    expect($body)->toContain($bodyNeedle);
    assertNoRawKey($subject.$body);
})->with([
    ['en', 'Confirm your inquiry', 'Confirm your inquiry'],
    ['fr', 'Confirmez votre message', 'Confirmez votre message'],
]);

it('renders the quote submitted email in both locales', function (string $locale, string $subjectNeedle, string $bodyNeedle) {
    App::setLocale($locale);
    $quote = Quote::factory()->submitted()->create();

    [$subject, $body] = renderMailable(new QuoteSubmittedMail($quote, 'https://example.test/responses'));

    expect($subject)->toContain($subjectNeedle);
    expect($body)->toContain($bodyNeedle);
    assertNoRawKey($subject.$body);
})->with([
    ['en', 'New quote for', 'You have a new quote'],
    ['fr', 'Nouveau devis pour', 'Vous avez reçu un nouveau devis'],
]);

it('renders the contact message email in both locales', function (string $locale, string $bodyNeedle) {
    App::setLocale($locale);
    $data = ['name' => 'Jane', 'email' => 'jane@example.test', 'subject' => 'Hello', 'message' => str_repeat('x', 30), 'company' => null, 'phone' => null];

    [$subject, $body] = renderMailable(new ContactMessageMail($data));

    expect($subject)->toContain('[CTH Contact] Hello');
    expect($body)->toContain($bodyNeedle);
    assertNoRawKey($subject.$body);
})->with([
    ['en', 'New contact form message'],
    ['fr', 'Nouveau message du formulaire de contact'],
]);

it('renders the error digest subject in both locales', function (string $locale, string $needle) {
    App::setLocale($locale);

    [$subject, $body] = renderMailable(new ErrorDigestMail(5, 'raw body'));

    expect($subject)->toContain($needle);
    assertNoRawKey($subject.$body);
})->with([
    ['en', 'Error digest'],
    ['fr', 'Récapitulatif des erreurs'],
]);

it('renders the company verified notification in both locales', function (string $locale, string $subjectNeedle, string $bodyNeedle) {
    App::setLocale($locale);
    $company = Company::factory()->create();
    $user = User::factory()->create();

    [$subject, $lines] = renderNotificationMail(new CompanyVerifiedNotification($company), $user);

    expect($subject)->toContain($subjectNeedle);
    expect($lines)->toContain($bodyNeedle);
    assertNoRawKey($subject.$lines);
})->with([
    ['en', 'Your company has been verified', 'has been verified by the Cameroon Timber Hub team'],
    ['fr', 'Votre société a été vérifiée', 'vérifiée par l\'équipe de Cameroon Timber Hub'],
]);

it('renders the document expiring notification in both locales', function (string $locale, string $subjectNeedle, string $bodyNeedle) {
    App::setLocale($locale);
    $type = DocumentType::factory()->create(['name' => 'Export Permit']);
    $document = CompanyDocument::factory()->expiring(15)->create(['document_type_id' => $type->id]);
    $document->setRelation('documentType', $type);
    $document->load('company');
    $user = User::factory()->create();

    [$subject, $lines] = renderNotificationMail(new DocumentExpiring($document, '15'), $user);

    expect($subject)->toContain($subjectNeedle);
    expect($lines)->toContain($bodyNeedle);
    assertNoRawKey($subject.$lines);
})->with([
    ['en', 'Compliance document expiry', 'expires in 15 days'],
    ['fr', 'Expiration d\'un document de conformité', 'expire dans 15 jours'],
]);

it('renders the RFQ routed to exporter notification in both locales', function (string $locale, string $subjectNeedle, string $bodyNeedle) {
    App::setLocale($locale);
    $rfq = Rfq::factory()->create(['reference_code' => 'RFQ-2026-ZZZZZ']);
    $user = User::factory()->create();

    [$subject, $lines] = renderNotificationMail(new RfqRoutedToExporter($rfq), $user);

    expect($subject)->toContain($subjectNeedle);
    expect($lines)->toContain($bodyNeedle);
    assertNoRawKey($subject.$lines);
})->with([
    ['en', 'New buyer lead', 'A verified buyer request has been routed'],
    ['fr', 'Nouvelle piste acheteur', 'Une demande d\'acheteur vérifié a été transmise'],
]);
