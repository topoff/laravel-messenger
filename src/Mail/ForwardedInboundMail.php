<?php

declare(strict_types=1);

namespace Topoff\Messenger\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Plain-text envelope around an inbound email that nobody handled. Built and
 * dispatched by InboundMailForwarder — recipients, Reply-To, BCC and the
 * attachments (original attachments plus the untouched .eml) are attached
 * there, so they stay assertable on the Mailable instance.
 *
 * The marker header identifies our own forwards: inbound mail carrying it is
 * never forwarded again (loop protection in ImapBounceProcessor).
 */
final class ForwardedInboundMail extends Mailable
{
    use Queueable, SerializesModels;

    public const string MARKER_HEADER = 'X-Topoff-Forwarded';

    public function __construct(
        public readonly string $originalFrom,
        public readonly string $originalTo,
        public readonly string $originalDate,
        public readonly string $originalSubject,
        public readonly string $originalBody,
        public readonly string $inboxKey,
        public readonly string $reason,
    ) {}

    /**
     * Subject only — recipients, Reply-To and BCC are set fluently by the
     * forwarder, so an envelope() would only shadow them (and Envelope::isFrom()
     * fatals on a mailable whose envelope carries no From).
     */
    public function build(): self
    {
        return $this->subject($this->forwardSubject());
    }

    public function content(): Content
    {
        return new Content(
            text: (string) config('messenger.mail.forwarded_inbound_view', 'messenger::forwardedInbound'),
            with: [
                'originalFrom' => $this->originalFrom,
                'originalTo' => $this->originalTo,
                'originalDate' => $this->originalDate,
                'originalSubject' => $this->originalSubject,
                'originalBody' => $this->originalBody,
                'inboxKey' => $this->inboxKey,
                'reason' => $this->reason,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [self::MARKER_HEADER => '1']);
    }

    public function forwardSubject(): string
    {
        $subject = trim($this->originalSubject);

        return 'Fwd: '.($subject === '' ? '(no subject)' : $subject);
    }
}
