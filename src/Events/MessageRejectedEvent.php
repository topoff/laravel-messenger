<?php

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessageRejectedEvent
{
    public function __construct(public string $reason, public Message $message) {}
}
