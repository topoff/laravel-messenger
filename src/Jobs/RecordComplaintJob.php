<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Events\MessageComplaintEvent;
use Topoff\Messenger\Jobs\Concerns\ExtractsSesMessageTags;

class RecordComplaintJob implements ShouldQueue
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
            Log::warning('RecordComplaintJob: Missing mail.messageId in SNS payload', ['payload_keys' => array_keys($this->message)]);

            return;
        }

        if (! data_get($this->message, 'complaint')) {
            Log::warning('RecordComplaintJob: Missing complaint data in SNS payload', ['messageId' => $messageId]);

            return;
        }

        $messageClass = config('messenger.models.message');
        $trackedMessages = $messageClass::query()->where('tracking_message_id', $messageId)->get();
        if ($trackedMessages->isEmpty()) {
            Log::error('RecordComplaintJob: No message found for tracking_message_id.', ['messageId' => $messageId]);

            return;
        }

        $trackedMessages->each(function ($trackedMessage): void {
            // Skip if this event is for a different recipient (e.g. BCC)
            if ($trackedMessage->tracking_recipient_contact !== null) {
                $eventRecipients = collect(data_get($this->message, 'complaint.complainedRecipients', []))
                    ->map(fn ($r) => mb_strtolower((string) data_get($r, 'emailAddress', '')));

                if (! $eventRecipients->contains(mb_strtolower((string) $trackedMessage->tracking_recipient_contact))) {
                    return;
                }
            }

            $meta = collect($trackedMessage->tracking_meta ?: []);
            $meta->put('complaint', true);
            $meta->put('success', false);
            $meta->put('complaint_time', data_get($this->message, 'complaint.timestamp'));

            $feedbackType = data_get($this->message, 'complaint.complaintFeedbackType');
            if ($feedbackType) {
                $meta->put('complaint_type', $feedbackType);
            }

            $meta->put('sns_message_complaint', $this->message);

            $sesTags = $this->extractSesMessageTags($this->message);
            if ($sesTags !== []) {
                $meta->put('ses_tags', $sesTags);
            }

            $trackedMessage->tracking_meta = $meta->toArray();
            $trackedMessage->save();

            Event::dispatch(new MessageComplaintEvent($trackedMessage));
        });
    }
}
