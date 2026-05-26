<?php

declare(strict_types=1);

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessageOpenedEvent
{
    public function __construct(public Message $message, public ?string $ipAddress) {}
}
