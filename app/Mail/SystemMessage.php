<?php

namespace App\Mail;

use App\Support\MergeTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A plain-text email sent straight away, outside the Messages outbox, so sign-in codes and
 * password links never appear in the message history.
 */
class SystemMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?Address $sender = null,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, from: $this->sender);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: MergeTemplate::textToHtml($this->bodyText),
            text: 'mail.system-message-text',
        );
    }
}
