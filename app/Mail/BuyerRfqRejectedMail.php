<?php

namespace App\Mail;

use App\Models\Rfq;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Polite "we could not route your request" email, with the admin's reason —
 * sent from RfqTriageService::reject() for verified, non-spam RFQs only.
 */
class BuyerRfqRejectedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Rfq $rfq,
        public string $reason,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.buyer_rfq_rejected.subject', ['reference' => $this->rfq->reference_code]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.buyer-rfq-rejected');
    }
}
