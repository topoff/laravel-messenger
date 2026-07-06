<?php

namespace Topoff\Messenger\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Topoff\Messenger\Models\Message;

/**
 * Fired when the fallback engine creates a follow-up message for an
 * undelivered original (v9, E79).
 */
class MessageFallbackCreatedEvent
{
    use Dispatchable;

    public function __construct(
        public Message $followUp,
        public Message $original,
    ) {}
}
