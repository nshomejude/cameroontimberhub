<?php

namespace App\Mail;

use App\Models\CompanyInquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InquiryVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyInquiry $inquiry,
        public string $verifyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your inquiry — Cameroon Timber Hub');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.inquiry-verification');
    }
}
