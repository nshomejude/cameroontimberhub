<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message submitted through the public /contact form, delivered to the
 * team mailbox with the sender set as reply-to.
 *
 * @property array{name: string, email: string, subject: string, message: string, company?: string|null, phone?: string|null} $data
 */
class ContactMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<string, string|null>  $data */
    public function __construct(public array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('notifications.contact_message.subject', ['subject' => $this->data['subject']]),
            replyTo: [new Address($this->data['email'], $this->data['name'])],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.contact-message');
    }
}
