<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Events\MessageRejectedEvent;
use Topoff\Messenger\Jobs\Concerns\ExtractsSesMessageTags;

class RecordRejectJob implements ShouldQueue
{
    use Dispatchable, ExtractsSesMessageTags, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public function __construct(public array $message) {}

    public function retryUntil(): \Illuminate\Support\Carbon
    {
        return now()->addDays(5);
    }

    public function handle(): void
    {
        $messageId = data_get($this->message, 'mail.messageId');
        if (! $messageId) {
            Log::warning('RecordRejectJob: Missing mail.messageId in SNS payload', ['payload_keys' => array_keys($this->message)]);

            return;
        }

        $reason = (string) data_get($this->message, 'reject.reason', 'unknown');

        $messageClass = config('messenger.models.message');
        $trackedMessages = $messageClass::query()->where('tracking_message_id', $messageId)->get();
        if ($trackedMessages->isEmpty()) {
            return;
        }

        $trackedMessages->each(function ($trackedMessage) use ($reason): void {
            $meta = collect($trackedMessage->tracking_meta ?: []);
            $meta->put('success', false);
            $meta->put('rejected', true);
            $meta->put('reject_reason', $reason);
            $meta->put('sns_message_reject', $this->message);

            $sesTags = $this->extractSesMessageTags($this->message);
            if ($sesTags !== []) {
                $meta->put('ses_tags', $sesTags);
            }

            $trackedMessage->tracking_meta = $meta->toArray();
            $trackedMessage->failed_at = now();
            $trackedMessage->save();

            Event::dispatch(new MessageRejectedEvent($reason, $trackedMessage));
        });
    }
}
