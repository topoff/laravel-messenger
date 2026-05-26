<?php

declare(strict_types=1);

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessageTrackingValidActionEvent
{
    public bool $skip = false;

    public function __construct(public Message $message) {}
}
