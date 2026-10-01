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
 * Tells the buyer their approved RFQ has been sent to N suppliers — sent from
 * RfqTriageService::route() only when N > 0 new routings were created.
 */
class BuyerRfqRoutedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Rfq $rfq,
        public int $count,
        public string $responsesUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('notifications.buyer_rfq_routed.subject', ['reference' => $this->rfq->reference_code]));
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.buyer-rfq-routed');
    }
}
