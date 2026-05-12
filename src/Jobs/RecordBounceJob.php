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
use Topoff\Messenger\Events\MessagePermanentBouncedEvent;
use Topoff\Messenger\Events\MessageTransientBouncedEvent;
use Topoff\Messenger\Jobs\Concerns\ExtractsSesMessageTags;
use Topoff\Messenger\Jobs\Concerns\RetriesOnMissingTrackedMessage;

class RecordBounceJob implements ShouldQueue
{
    use Dispatchable, ExtractsSesMessageTags, InteractsWithQueue, Queueable, RetriesOnMissingTrackedMessage, SerializesModels;

    public int $maxExceptions = 3;

    public function __construct(public array $message) {}

    public function retryUntil(): Carbon
    {
        return Carbon::now()->addDays(5);
    }

    public function handle(): void
    {
        $messageId = data_get($this->message, 'mail.messageId');
        if (! $messageId) {
            Log::warning('RecordBounceJob: Missing mail.messageId in SNS payload', ['payload_keys' => array_keys($this->message)]);

            return;
        }

        if (! data_get($this->message, 'bounce')) {
            Log::warning('RecordBounceJob: Missing bounce data in SNS payload', ['messageId' => $messageId]);

            return;
        }

        $trackedMessages = $this->findTrackedMessagesOrRetry($messageId, 'RecordBounceJob');
        if (! $trackedMessages instanceof Collection) {
            return;
        }

        $trackedMessages->each(function ($trackedMessage): void {
            // Skip if this event is for a different recipient (e.g. BCC)
            if ($trackedMessage->tracking_recipient_contact !== null) {
                $eventRecipients = collect(data_get($this->message, 'bounce.bouncedRecipients', []))
                    ->map(fn ($r) => mb_strtolower((string) data_get($r, 'emailAddress', '')));

                if (! $eventRecipients->contains(mb_strtolower((string) $trackedMessage->tracking_recipient_contact))) {
                    return;
                }
            }

            $meta = collect($trackedMessage->tracking_meta ?: []);
            $failures = collect($meta->get('failures', []));

            foreach ((array) data_get($this->message, 'bounce.bouncedRecipients', []) as $failure) {
                $failures->push($failure);
            }

            $meta->put('failures', $failures->toArray());

            if (! $meta->has('success')) {
                $meta->put('success', false);
            }

            $meta->put('sns_message_bounce', $this->message);

            $sesTags = $this->extractSesMessageTags($this->message);
            if ($sesTags !== []) {
                $meta->put('ses_tags', $sesTags);
            }

            $trackedMessage->tracking_meta = $meta->toArray();
            $trackedMessage->bounced_at = $this->parseEventTimestamp(data_get($this->message, 'bounce.timestamp'));
            $trackedMessage->save();

            $bounceType = (string) data_get($this->message, 'bounce.bounceType');
            if ($bounceType === 'Permanent') {
                Event::dispatch(new MessagePermanentBouncedEvent($trackedMessage));
            } else {
                Event::dispatch(new MessageTransientBouncedEvent(
                    (string) data_get($this->message, 'bounce.bounceSubType', ''),
                    (string) data_get(collect(data_get($this->message, 'bounce.bouncedRecipients', []))->first(), 'diagnosticCode', ''),
                    $trackedMessage
                ));
            }
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
