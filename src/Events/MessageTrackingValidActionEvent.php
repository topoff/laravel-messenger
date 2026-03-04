<?php

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessageTrackingValidActionEvent
{
    public bool $skip = false;

    public function __construct(public Message $message) {}
}
