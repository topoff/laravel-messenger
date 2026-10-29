<?php

declare(strict_types=1);

namespace Topoff\Messenger\Events;

use Topoff\Messenger\Models\Message;

/**
 * A provider reported a final delivery failure for an SMS (AWS event or
 * Vonage DLR). Separate from MessageRejectedEvent so e-mail bounce listeners
 * do not react to SMS failures.
 */
class SmsDeliveryFailedEvent
{
    public function __construct(public string $status, public Message $message, public string $provider) {}
}
