<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\Jobs\Concerns\ExtractsSesMessageTags;
use Topoff\Messenger\Jobs\Concerns\RetriesOnMissingTrackedMessage;

class RecordDeliveryJob implements ShouldQueue
{
    use Dispatchable, ExtractsSesMessageTags, InteractsWithQueue, Queueable, RetriesOnMissingTrackedMessage, SerializesModels;

    public int $maxExceptions = 3;

    public function __construct(public array $message) {}

    public function retryUntil(): Carbon
    {
        return now()->addDays(5);
    }

    public function handle(): void
    {
        $messageId = data_get($this->message, 'mail.messageId');
        if (! $messageId) {
            Log::warning('RecordDeliveryJob: Missing mail.messageId in SNS payload', ['payload_keys' => array_keys($this->message)]);

            return;
        }

        if (! data_get($this->message, 'delivery')) {
            Log::warning('RecordDeliveryJob: Missing delivery data in SNS payload', ['messageId' => $messageId]);

            return;
        }

        $trackedMessages = $this->findTrackedMessagesOrRetry($messageId, 'RecordDeliveryJob');
        if (! $trackedMessages instanceof Collection) {
            return;
        }

        $trackedMessages->each(function ($trackedMessage): void {
            // Skip if this event is for a different recipient (e.g. BCC)
            if ($trackedMessage->tracking_recipient_contact !== null) {
                $eventRecipients = collect(data_get($this->message, 'delivery.recipients', []))
                    ->map(fn ($email) => mb_strtolower((string) $email));

                if (! $eventRecipients->contains(mb_strtolower((string) $trackedMessage->tracking_recipient_contact))) {
                    return;
                }
            }

            $meta = collect($trackedMessage->tracking_meta ?: []);
            $meta->put('smtpResponse', data_get($this->message, 'delivery.smtpResponse'));
            $meta->put('success', true);
            $meta->put('delivered_at', data_get($this->message, 'delivery.timestamp'));
            $meta->put('sns_message_delivery', $this->message);

            $sesTags = $this->extractSesMessageTags($this->message);
            if ($sesTags !== []) {
                $meta->put('ses_tags', $sesTags);
            }

            $trackedMessage->tracking_meta = $meta->toArray();
            $trackedMessage->delivered_at = $this->parseEventTimestamp(data_get($this->message, 'delivery.timestamp'));
            $trackedMessage->save();

            Event::dispatch(new MessageDeliveredEvent($trackedMessage));
        });
    }

    protected function parseEventTimestamp(mixed $timestamp): Carbon
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return now();
        }

        try {
            return Carbon::parse($timestamp);
        } catch (\Throwable) {
            return now();
        }
    }
}
