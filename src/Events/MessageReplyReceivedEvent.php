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
 *
 * Listeners run synchronously, so a listener that consumed the reply can say so
 * via markHandled(). The processor forwards every reply left un-handled to
 * messenger.imap.forward.unhandled_to, if configured.
 */
class MessageReplyReceivedEvent
{
    private bool $handled = false;

    /**
     * @param  array<string, list<string>>  $rawHeaders  full inbound headers, lowercased
     * @param  list<array{filename: string, mime: string, size: int}>  $attachments  attachment manifest (no payloads)
     * @param  string|null  $matchedVia  which MessageMatcher path produced $message (a MatchOutcome::VIA_* value; null when unmatched). Listeners that act on the match — like inserting into a customer-facing chat — should require an id-based path (MatchOutcome::isIdBased()-equivalent), because the recipient-window fallback only proves the sender knew an address we mailed, which a spoofed From satisfies.
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
        public readonly ?string $matchedVia = null,
    ) {}

    /**
     * Signal that a listener consumed this reply, so it must not be forwarded.
     */
    public function markHandled(): void
    {
        $this->handled = true;
    }

    public function isHandled(): bool
    {
        return $this->handled;
    }
}
