<?php

namespace Topoff\Messenger\Listeners;

use Exception;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Str;
use Topoff\Messenger\Models\MessageLog;

class LogEmailToMessageLogListener
{
    public function handle(MessageSent $event): void
    {
        try {
            $message = $event->message;
            $toHeader = $message->getHeaders()->get('To');

            if ($toHeader === null) {
                return;
            }

            $messageLogModelClass = config('messenger.models.message_log', MessageLog::class);
            $messageLogModelClass::query()->create([
                'channel' => 'mail',
                'to' => Str::limit($toHeader->toString(), 97),
                'subject' => Str::limit($message->getHeaders()->get('Subject')?->toString() ?? '', 77),
                'cc' => Str::limit($message->getHeaders()->get('Cc')?->toString() ?? '', 97),
                'bcc' => Str::limit($message->getHeaders()->get('Bcc')?->toString() ?? '', 57),
                'has_attachment' => (bool) $message->getAttachments(),
            ]);
        } catch (Exception) {
            // Intentionally swallow errors: logging should never block mail delivery.
        }
    }
}
