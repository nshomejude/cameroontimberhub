<?php

namespace App\Mail;

use App\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the buyer a supplier has quoted, and hands them the signed link to
 * their own responses screen. Queued — submission must not wait on SMTP.
 */
class QuoteSubmittedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Quote $quote,
        public string $responsesUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('notifications.quote_submitted.subject', [
                'reference' => $this->quote->rfq->reference_code,
                'company' => $this->quote->company->name,
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.quote-submitted');
    }
}
