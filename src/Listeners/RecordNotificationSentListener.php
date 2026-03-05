<?php

namespace Topoff\Messenger\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordNotificationSentListener
{
    public function handle(NotificationSent $event): void
    {
        try {
            $messengerMessageId = $event->notification->messengerMessageId ?? null;
            if (! $messengerMessageId) {
                return;
            }

            if ($event->channel !== 'vonage') {
                return;
            }

            $response = $event->response;
            if (! is_object($response) || ! ($response instanceof \Traversable)) {
                return;
            }

            /** @var object|null $sentSms */
            $sentSms = null;
            foreach ($response as $item) {
                $sentSms = $item;
                break;
            }

            if (! $sentSms || ! method_exists($sentSms, 'getMessageId')) {
                return;
            }

            $messageClass = config('messenger.models.message');
            $message = $messageClass::find($messengerMessageId);
            if (! $message) {
                return;
            }

            $message->tracking_message_id = $sentSms->getMessageId();
            $message->tracking_recipient_contact = method_exists($sentSms, 'getTo') ? $sentSms->getTo() : null;

            $meta = collect($message->tracking_meta ?: []);
            if (method_exists($sentSms, 'getStatus')) {
                $meta->put('vonage_status', (string) $sentSms->getStatus());
            }
            if (method_exists($sentSms, 'getNetwork')) {
                $meta->put('vonage_network', $sentSms->getNetwork());
            }
            if (method_exists($sentSms, 'getPrice')) {
                $meta->put('vonage_message_price', $sentSms->getPrice());
            }
            $message->tracking_meta = $meta->toArray();

            $message->save();
        } catch (Throwable $t) {
            Log::warning('RecordNotificationSentListener: Failed to record Vonage response', [
                'error' => $t->getMessage(),
                'messengerMessageId' => $event->notification->messengerMessageId ?? null,
            ]);
        }
    }
}
