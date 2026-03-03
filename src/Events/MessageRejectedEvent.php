<?php

namespace Topoff\MailManager\Events;

use Topoff\MailManager\Models\Message;

class MessageRejectedEvent
{
    public function __construct(public string $reason, public Message $message) {}
}
