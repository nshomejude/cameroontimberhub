<?php

namespace App\Services;

use App\Mail\InquiryVerificationMail;
use App\Mail\RfqVerificationMail;
use App\Models\Company;
use App\Models\CompanyInquiry;
use App\Models\Rfq;
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

    public function createRfq(array $header, array $items, ?string $source = null): Rfq
    {
        $rfq = DB::transaction(function () use ($header, $items, $source) {
            $rfq = Rfq::create(array_merge($header, [
                'reference_code' => $this->references->generate(),
                'status' => 'new',
                'visibility' => 'public',
                'ip_address' => request()->ip(),
                'source' => $source,
            ]));

            foreach ($items as $item) {
                $rfq->items()->create($item);
            }

            return $rfq;
        });

        $rfq->load('items');
        $this->risk->evaluate($rfq);

        Mail::to($rfq->buyer_email)->send(new RfqVerificationMail($rfq, $this->rfqVerifyUrl($rfq)));

        return $rfq;
    }

    public function createInquiry(Company $company, array $data): CompanyInquiry
    {
        $inquiry = $company->inquiries()->create(array_merge($data, [
            'status' => 'new',
            'ip_address' => request()->ip(),
        ]));

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
