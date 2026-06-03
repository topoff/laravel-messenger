<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * Outcome of inspecting an inbound IMAP message in the reply-to inbox.
 *
 * HardBounce / SoftBounce / Complaint are derived from RFC 3464 (DSN) and
 * RFC 5965 (ARF). AutoReply covers vacation responders. Reply is a genuine
 * human reply we route to the MessageReplyReceivedEvent. Unknown is the
 * fallback for inbound mail we cannot interpret.
 */
enum BounceClassification: string
{
    case HardBounce = 'hard_bounce';
    case SoftBounce = 'soft_bounce';
    case Complaint = 'complaint';
    case AutoReply = 'auto_reply';
    case Reply = 'reply';
    case Unknown = 'unknown';

    public function isBounce(): bool
    {
        return $this === self::HardBounce || $this === self::SoftBounce;
    }
}
