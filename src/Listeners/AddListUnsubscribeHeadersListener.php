<?php

namespace Topoff\Messenger\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;
use Topoff\Messenger\Models\MessageType;

/**
 * RFC 8058 List-Unsubscribe (v9, E79): marketing-class messages get the
 * `List-Unsubscribe` (signed one-click URL) and `List-Unsubscribe-Post`
 * headers so mailbox providers can render their native unsubscribe
 * button. Transactional messages are never touched. The endpoint writes
 * a `marketing` opt-out through ConsentService.
 */
class AddListUnsubscribeHeadersListener
{
    public function handle(MessageSending $event): void
    {
        try {
            if (! Arr::has($event->data, 'messageModel')) {
                return;
            }

            $messageModel = $event->data['messageModel'];

            if ($messageModel->messageType?->message_class !== MessageType::CLASS_MARKETING) {
                return;
            }

            $url = URL::signedRoute('messenger.unsubscribe', ['message' => $messageModel->id]);

            $headers = $event->message->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<'.$url.'>');
            $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        } catch (Throwable $t) {
            Log::error('AddListUnsubscribeHeadersListener: Failed to add headers.', ['error' => $t->getMessage()]);
        }
    }
}
