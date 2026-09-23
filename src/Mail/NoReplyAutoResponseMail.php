<?php

declare(strict_types=1);

namespace Topoff\Messenger\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Plain-text automatic answer to a sender who wrote to a send-only address
 * (e.g. no-reply@…): the mailbox is not read, please use the monitored
 * contact address instead. Built and dispatched by UnhandledMailAutoResponder —
 * recipient, From, Reply-To and BCC are set fluently there, so they stay
 * assertable on the Mailable instance.
 *
 * The original body is deliberately NOT quoted: reflecting inbound content
 * back out through our own sending path would make us a backscatter vector.
 *
 * The marker header identifies our own auto-responses: inbound mail carrying
 * it is never answered or forwarded again (loop protection). Auto-Submitted,
 * X-Auto-Response-Suppress and Precedence mark the mail as automated so other
 * autoresponders stay silent in return.
 */
final class NoReplyAutoResponseMail extends Mailable
{
    use Queueable, SerializesModels;

    public const string MARKER_HEADER = 'X-Topoff-Auto-Replied';

    public function __construct(
        public readonly string $noReplyAddress,
        public readonly string $contactAddress,
        public readonly string $originalSubject,
        public readonly ?string $originalMessageId,
    ) {}

    /**
     * Subject only — recipient, From, Reply-To and BCC are set fluently by the
     * responder, so an envelope() would only shadow them (and
     * Envelope::isFrom() fatals on a mailable whose envelope carries no From).
     */
    public function build(): self
    {
        return $this->subject($this->responseSubject());
    }

    public function content(): Content
    {
        return new Content(
            text: (string) config('messenger.mail.no_reply_auto_response_view', 'messenger::noReplyAutoResponse'),
            with: [
                'noReplyAddress' => $this->noReplyAddress,
                'contactAddress' => $this->contactAddress,
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            references: $this->originalMessageId === null ? [] : [$this->originalMessageId],
            text: [
                self::MARKER_HEADER => '1',
                'Auto-Submitted' => 'auto-replied',
                'X-Auto-Response-Suppress' => 'All',
                'Precedence' => 'auto_reply',
            ],
        );
    }

    public function responseSubject(): string
    {
        $subject = trim($this->originalSubject);

        return $subject === ''
            ? 'Ihre E-Mail an eine unbeaufsichtigte Adresse'
            : 'Re: '.$subject;
    }
}
