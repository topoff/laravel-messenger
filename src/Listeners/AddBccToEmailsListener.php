<?php

namespace Topoff\Messenger\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Message;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

class AddBccToEmailsListener
{
    /**
     * Handle the event.
     */
    public function handle(MessageSending $event): void
    {
        try {
            $checker = config('messenger.bcc.check_should_add_bcc');
            if (is_callable($checker) && ! $checker()) {
                return;
            }

            if (config('mail.bcc.address') === null) {
                return;
            }

            /** @var Message $message */
            $message = $event->message;

            // Only the Messages sent with the SendMessageJob do have a messageModel,
            // the Framework messages like the Verification Email do not have this
            if (Arr::has($event->data, 'messageModel')) {
                $messageModel = $event->data['messageModel'];

                if ($messageModel->messageType?->dev_bcc === true) {
                    $message->addBcc(config('mail.bcc.address'));
                }
            } else {
                $message->addBcc(config('mail.bcc.address'));
            }

        } catch (Throwable $t) {
            Log::error('AddBccToEmailsListener: Failed to add BCC.', ['error' => $t->getMessage()]);
        }
    }
}
