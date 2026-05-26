<?php

declare(strict_types=1);

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

class MessageTransientBouncedEvent
{
    public function __construct(
        public string $bounceSubType,
        public string $diagnosticCode,
        public Message $message
    ) {}
}
