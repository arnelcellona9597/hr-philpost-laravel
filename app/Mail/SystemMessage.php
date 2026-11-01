<?php

namespace App\Mail;

use App\Models\NotificationSetting;
use App\Support\EmailLayout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One branded email: sign-in codes and password links sent straight away, and messages from the
 * outbox, which pass their sender's name and any attachments.
 */
class SystemMessage extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{name: string, bytes: string, mime: string}>  $files
     */
    public function __construct(
        public string $subjectLine,
        public string $bodyText,
        public ?Address $sender = null,
        public ?string $senderName = null,
        public array $files = [],
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $replyTo = NotificationSetting::current()->reply_to;

        return new Envelope(subject: $this->subjectLine, from: $this->sender, replyTo: $replyTo ? [new Address($replyTo)] : []);
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            htmlString: EmailLayout::html($this->subjectLine, $this->bodyText, $this->senderName),
            text: 'mail.system-message-text',
            with: ['plainText' => EmailLayout::text($this->subjectLine, $this->bodyText, $this->senderName)],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromData(fn (): string => $file['bytes'], $file['name'])->withMime($file['mime']),
            $this->files,
        );
    }
}
