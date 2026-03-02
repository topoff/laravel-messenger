<?php

namespace Topoff\MailManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;
use Topoff\MailManager\MailHandler\MainMailHandler;

class ResendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $messageId) {}

    public function handle(): void
    {
        $messageClass = config('mail-manager.models.message');
        $message = $messageClass::query()->with('messageType')->findOrFail($this->messageId);

        $handlerClass = $message->messageType?->single_mail_handler;
        if (! $handlerClass) {
            throw new RuntimeException("ResendMessageJob: No single_mail_handler for message [{$this->messageId}]");
        }

        /** @var MainMailHandler $mailHandler */
        $mailHandler = new $handlerClass($message);
        $mailHandler->send();
    }
}
