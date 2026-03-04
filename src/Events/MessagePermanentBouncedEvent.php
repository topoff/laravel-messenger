<?php

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessagePermanentBouncedEvent
{
    public function __construct(public string $recipientEmail, public Message $message) {}
}
