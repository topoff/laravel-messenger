<?php

declare(strict_types=1);

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Services\Imap\BounceClassification;

/**
 * Low-level event fired once per inbound IMAP message after classification.
 * Useful for observability — count bounces vs replies, alert on Unknown spikes.
 *
 * For business-level reactions, prefer the higher-level events
 * (MessagePermanentBouncedEvent, MessageReplyReceivedEvent, etc).
 */
class ImapMessageProcessedEvent
{
    /**
     * @param  list<int>  $matchedMessageIds  IDs of Topoff\Messenger\Models\Message rows matched (may be empty)
     */
    public function __construct(
        public readonly string $inboxKey,
        public readonly string $imapUid,
        public readonly BounceClassification $classification,
        public readonly array $matchedMessageIds,
    ) {}
}
