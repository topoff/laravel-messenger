<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageOpenedEvent;
use Topoff\Messenger\Models\Message;

class RecordOpenJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 3;

    public function __construct(
        public int $messageId,
        public ?string $ipAddress
    ) {}

    public function retryUntil(): Carbon
    {
        return Carbon::now()->addDays(5);
    }

    public function handle(): void
    {
        /** @var class-string<Message> $messageClass */
        $messageClass = config('messenger.models.message');

        $message = $messageClass::on((new $messageClass)->getConnectionName())
            ->findOrFail($this->messageId);

        $message->increment('tracking_opens');

        Event::dispatch(new MessageOpenedEvent($message, $this->ipAddress));
    }
}
