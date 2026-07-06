<?php

namespace Topoff\Messenger\Services;

use Topoff\Messenger\Models\MessageOptOut;
use Topoff\Messenger\Models\MessageType;

/**
 * Central consent guard (v9, E79): marketing-class message types are
 * blocked for opted-out receivers — at creation time (MessageService)
 * and again at send time (handlers), so an opt-out between creation and
 * send still wins. Transactional types never consult the opt-outs.
 */
class ConsentService
{
    public function optOut(string $receiverClass, int|string $receiverId, string $scope = 'marketing'): MessageOptOut
    {
        return MessageOptOut::firstOrCreate([
            'receiver_type' => $receiverClass,
            'receiver_id' => (string) $receiverId,
            'scope' => $scope,
        ]);
    }

    public function optIn(string $receiverClass, int|string $receiverId, string $scope = 'marketing'): void
    {
        MessageOptOut::where('receiver_type', $receiverClass)
            ->where('receiver_id', (string) $receiverId)
            ->where('scope', $scope)
            ->delete();
    }

    public function isOptedOut(string $receiverClass, int|string $receiverId, MessageType $messageType): bool
    {
        if ($messageType->message_class !== MessageType::CLASS_MARKETING) {
            return false;
        }

        return MessageOptOut::where('receiver_type', $receiverClass)
            ->where('receiver_id', (string) $receiverId)
            ->whereIn('scope', [
                'marketing',
                'channel:'.$messageType->channel,
                'type:'.$messageType->notification_class,
            ])
            ->exists();
    }
}
