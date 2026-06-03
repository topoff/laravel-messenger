<?php

declare(strict_types=1);

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

/**
 * Fired when a genuine human reply is received via IMAP in one of the
 * configured reply-to inboxes (messenger.imap.inboxes).
 *
 * `$message` is the originating outbound Message we believe this is a reply
 * to. It is nullable because not every inbound message can be matched
 * (unsolicited inbound, forwards, manual outreach). Consumers must handle
 * the null case explicitly.
 */
class MessageReplyReceivedEvent
{
    /**
     * @param  array<string, list<string>>  $rawHeaders  full inbound headers, lowercased
     * @param  list<array{filename: string, mime: string, size: int}>  $attachments  attachment manifest (no payloads)
     */
    public function __construct(
        public readonly ?Message $message,
        public readonly string $inboxKey,
        public readonly string $fromAddress,
        public readonly string $subject,
        public readonly string $textBody,
        public readonly ?string $htmlBody,
        public readonly array $rawHeaders,
        public readonly array $attachments,
    ) {}
}
