<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Topoff\Messenger\Events\MessageLinkClickedEvent;
use Topoff\Messenger\Models\Message;

class RecordLinkClickJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 3;

    public function __construct(
        public int $messageId,
        public string $url,
        public ?string $ipAddress
    ) {}

    public function retryUntil(): \Illuminate\Support\Carbon
    {
        return now()->addDays(5);
    }

    public function handle(): void
    {
        /** @var class-string<Message> $messageClass */
        $messageClass = config('messenger.models.message');

        $connection = (new $messageClass)->getConnectionName();
        $updatedMessage = DB::connection($connection)->transaction(function () use ($messageClass, $connection): ?Message {
            /** @var Message|null $message */
            $message = $messageClass::on($connection)
                ->whereKey($this->messageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                return null;
            }

            $meta = collect($message->tracking_meta ?: []);
            $clickedUrls = collect($meta->get('clicked_urls', []));
            $clickedUrls->put($this->url, ((int) $clickedUrls->get($this->url, 0)) + 1);
            $meta->put('clicked_urls', $clickedUrls->toArray());

            $message->tracking_clicks = (int) $message->tracking_clicks + 1;
            $message->tracking_meta = $meta->toArray();
            $message->save();

            return $message;
        });

        if (! $updatedMessage) {
            throw new RuntimeException("RecordLinkClickJob: Message [{$this->messageId}] not found");
        }

        Event::dispatch(new MessageLinkClickedEvent($updatedMessage, $this->ipAddress, $this->url));
    }
}
