<?php

declare(strict_types=1);

namespace Topoff\Messenger\Notifications\Concerns;

use Topoff\Messenger\Services\Sms\SmsClientReference;

/**
 * For SMS notifications sent through the messenger: pass the result to
 * `VonageMessage::clientReference()` so delivery receipts of every SMS part are
 * matched to the messenger message (multipart SMS get one provider id per part).
 *
 *     return (new VonageMessage)->content($text)->clientReference($this->messengerClientReference());
 */
trait TracksSmsDelivery
{
    protected function messengerClientReference(): string
    {
        return SmsClientReference::for($this->messengerMessageId ?? null);
    }
}
