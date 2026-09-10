<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Daily operational error digest (production-readiness plan Task A2), emailed
 * by the ops:error-digest command to config('mail.ops_address'). Plain text —
 * a count plus the top offenders parsed from the `errors` log channel.
 */
class ErrorDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $total,
        public string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.config('app.name').'] Error digest — '.$this->total.' error(s) in the last window',
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.error-digest',
            with: ['body' => $this->body],
        );
    }
}
