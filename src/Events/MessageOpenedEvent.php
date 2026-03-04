<?php

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessageOpenedEvent
{
    public function __construct(public Message $message, public ?string $ipAddress) {}
}
