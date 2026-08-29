<?php

namespace App\Services;

use App\Enums\ConsentPurpose;
use App\Mail\InquiryVerificationMail;
use App\Mail\RfqVerificationMail;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Buyer intake (RFQ + inquiry): honeypot/min-time guard, persistence, risk
 * scoring, the signed email-verification gate, and post-verify lead creation.
 */
class IntakeService
{
    public function __construct(
        private readonly RfqReferenceGenerator $references,
        private readonly RfqRiskService $risk,
        private readonly LeadFlowService $leads,
        private readonly AntiSpamService $antiSpam,
    ) {}

    public function honeypotTripped(array $data): bool
    {
        return $this->antiSpam->honeypotTripped($data, 'buyer_email');
    }

    public function createRfq(array $header, array $items, ?string $source = null, bool $consentGiven = false): Rfq
    {
        $rfq = DB::transaction(function () use ($header, $items, $source, $consentGiven) {
            // Guests stay guests, but when the address already has an account we
            // bind the RFQ to it so it shows up in that buyer's own screens
            // without needing the signed link.
            $owner = isset($header['buyer_email'])
                ? User::whereRaw('lower(email) = ?', [strtolower(trim($header['buyer_email']))])->first()
                : null;

            $rfq = Rfq::create(array_merge($header, [
                'user_id' => $owner?->getKey(),
                'reference_code' => $this->references->generate(),
                'status' => 'new',
                'visibility' => 'public',
                'ip_address' => request()->ip(),
                'source' => $source,
            ]));

            foreach ($items as $item) {
                $rfq->items()->create($item);
            }

            // The wizard's consent checkbox ("share this request with
            // verified exporters and contact me by email") is validated as
            // `accepted` (RfqWizard::rules()) but historically discarded —
            // see docs/superpowers/plans/2026-08-27-persisted-consent.md.
            // A checked box gets a persisted, revocable Consent row here;
            // RfqTriageService::route() refuses to route an RFQ whose
            // consent has since been revoked.
            if ($consentGiven) {
                $rfq->consents()->create([
                    'purpose' => ConsentPurpose::RfqExporterSharing,
                    'scope' => ['shared_with' => 'verified_exporters', 'contact_channel' => 'email'],
                    'granted_at' => now(),
                    'evidence' => [
                        'ip_address' => request()->ip(),
                        'user_agent' => substr((string) request()->userAgent(), 0, 512),
                        'captured_at' => now()->toIso8601String(),
                    ],
                ]);
            }

            return $rfq;
        });

        $rfq->load('items');
        $this->risk->evaluate($rfq);

        $this->sendRfqVerificationMail($rfq);

        return $rfq;
    }

    /**
     * Send (or re-send) the signed 48-hour confirmation link for an RFQ.
     *
     * Always addressed to `rfqs.buyer_email` — the address recorded on the row,
     * never one supplied by the caller — so a resend cannot be redirected to a
     * third party. `rfqVerifyUrl()` mints a fresh temporary signed URL each
     * time, so an expired link is recoverable without touching the RFQ.
     */
    public function sendRfqVerificationMail(Rfq $rfq): void
    {
        Mail::to($rfq->buyer_email)->send(new RfqVerificationMail($rfq, $this->rfqVerifyUrl($rfq)));
    }

    public function createInquiry(Company $company, array $data, bool $consentGiven = false): CompanyInquiry
    {
        $inquiry = $company->inquiries()->create(array_merge($data, [
            'status' => 'new',
            'ip_address' => request()->ip(),
        ]));

        // The inquiry form's consent checkbox ("share this inquiry with the
        // company") is validated as `accepted` (InquiryController::store())
        // but historically discarded — see
        // docs/superpowers/plans/2026-08-28-inquiry-consent.md. A checked
        // box gets a persisted, revocable Consent row here, mirroring how
        // createRfq() above handles the RFQ wizard's own checkbox.
        if ($consentGiven) {
            $inquiry->consents()->create([
                'purpose' => ConsentPurpose::CompanyInquirySharing,
                'granted_at' => now(),
                'scope' => ['shared_with' => 'company'],
                'evidence' => [
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ],
            ]);
        }

        Mail::to($inquiry->email)->send(new InquiryVerificationMail($inquiry, $this->inquiryVerifyUrl($inquiry)));

        return $inquiry;
    }

    public function verifyRfq(Rfq $rfq): void
    {
        if (! $rfq->email_verified_at) {
            $rfq->update(['email_verified_at' => now()]);
            $rfq->load('items');
            $this->risk->evaluate($rfq);
        }
    }

    public function verifyInquiry(CompanyInquiry $inquiry): void
    {
        if (! $inquiry->email_verified_at) {
            $inquiry->update(['email_verified_at' => now()]);
            $this->leads->createFromInquiry($inquiry);
        }
    }

    public function rfqVerifyUrl(Rfq $rfq): string
    {
        return URL::temporarySignedRoute('rfq.verify', now()->addHours(48), [
            'rfq' => $rfq->getKey(),
            'h' => sha1($rfq->buyer_email),
        ]);
    }

    public function inquiryVerifyUrl(CompanyInquiry $inquiry): string
    {
        return URL::temporarySignedRoute('inquiry.verify', now()->addHours(48), [
            'inquiry' => $inquiry->getKey(),
            'h' => sha1($inquiry->email),
        ]);
    }
}
