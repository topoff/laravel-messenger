<?php

namespace Topoff\MailManager\Tracking;

use Topoff\MailManager\Jobs\ResendMessageJob;
use Topoff\MailManager\Models\Message;

class MessageResender
{
    public function resend(Message $original): Message
    {
        $messageClass = $this->messageModelClass();

        $resentMeta = collect($original->tracking_meta ?: []);
        $resentMeta->put('resent_from_message_id', $original->id);

        /** @var Message $newMessage */
        $newMessage = $messageClass::query()->create([
            'sender_type' => $original->sender_type,
            'sender_id' => $original->sender_id,
            'receiver_type' => $original->receiver_type,
            'receiver_id' => $original->receiver_id,
            'company_id' => $original->company_id,
            'message_type_id' => $original->message_type_id,
            'messagable_type' => $original->messagable_type,
            'messagable_id' => $original->messagable_id,
            'params' => $original->params,
            'locale' => $original->locale,
            'scheduled_at' => null,
            'reserved_at' => null,
            'error_at' => null,
            'sent_at' => null,
            'failed_at' => null,
            'attempts' => 0,
            'error_code' => null,
            'error_message' => null,
            'tracking_hash' => null,
            'tracking_message_id' => null,
            'tracking_sender_name' => null,
            'tracking_sender_contact' => null,
            'tracking_recipient_name' => null,
            'tracking_recipient_contact' => null,
            'tracking_subject' => null,
            'tracking_opens' => 0,
            'tracking_clicks' => 0,
            'tracking_opened_at' => null,
            'tracking_clicked_at' => null,
            'tracking_content' => null,
            'tracking_content_path' => null,
            'tracking_meta' => $resentMeta->toArray(),
        ]);

        ResendMessageJob::dispatch($newMessage->id)->onQueue(config('mail-manager.tracking.tracker_queue'));

        return $newMessage;
    }

    /**
     * @return class-string<Message>
     */
    private function messageModelClass(): string
    {
        $modelClass = config('mail-manager.models.message');

        if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_a($modelClass, Message::class, true)) {
            throw new \RuntimeException('Invalid mail-manager.models.message configuration.');
        }

        return $modelClass;
    }
}
