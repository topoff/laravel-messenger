<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Topoff\Messenger\MailHandler\MainMailHandler;

class ResendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $messageClass = config('messenger.models.message');
        $message = $messageClass::query()->with('messageType')->findOrFail($this->messageId);

        $handlerClass = $message->messageType?->single_handler;
        if (! $handlerClass) {
            throw new RuntimeException("ResendMessageJob: No single_handler for message [{$this->messageId}]");
        }

        /** @var MainMailHandler $mailHandler */
        $mailHandler = new $handlerClass($message);
        $mailHandler->send();
    }
}
