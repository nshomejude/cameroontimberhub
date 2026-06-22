<?php

namespace App\Mail;

use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RfqVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Rfq $rfq,
        public string $verifyUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your quote request — '.$this->rfq->reference_code);
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.rfq-verification');
    }
}
