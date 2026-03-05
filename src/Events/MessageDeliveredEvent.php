<?php

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessageDeliveredEvent
{
    public function __construct(public Message $message) {}
}
