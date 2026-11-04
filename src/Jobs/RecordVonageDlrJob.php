<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Jobs\Concerns\ParsesEventTimestamp;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Services\Sms\SmsClientReference;
use Topoff\Messenger\Services\Sms\SmsDeliveryRecorder;

class RecordVonageDlrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, ParsesEventTimestamp, Queueable, SerializesModels;

    /** Lookup attempts while the DLR overtook the send listener (provider id not stored yet). */
    public const int MAX_LOOKUP_ATTEMPTS = 6;

    public int $maxExceptions = 3;

    /** @param  array<string, mixed>  $data */
    public function __construct(public array $data) {}

    public function retryUntil(): Carbon
    {
        return Carbon::now()->addDays(5);
    }

    public function handle(?SmsDeliveryRecorder $recorder = null): void
    {
        $recorder ??= app(SmsDeliveryRecorder::class);

        $vonageMessageId = $this->data['messageId'] ?? null;
        if (! $vonageMessageId) {
            Log::warning('RecordVonageDlrJob: Missing messageId in DLR payload');

            return;
        }

        $vonageMessageId = (string) $vonageMessageId;
        $messengerMessageId = SmsClientReference::messengerMessageId($this->data['client-ref'] ?? null);
        $message = $recorder->findMessage($messengerMessageId, $vonageMessageId);
        $status = (string) ($this->data['status'] ?? '');

        if (! $message) {
            if ($this->retryLookup()) {
                return;
            }

            if ($messengerMessageId !== null) {
                Log::error('RecordVonageDlrJob: No message found for tracking_message_id.', ['vonageMessageId' => $vonageMessageId, 'messengerMessageId' => $messengerMessageId]);
            } else {
                // Without the messenger's client reference: an SMS sent outside the
                // messenger (no messenger id) or a part of a message sent before v8.12.
                Log::warning('RecordVonageDlrJob: No message found for tracking_message_id (no messenger client reference).', ['vonageMessageId' => $vonageMessageId]);
            }

            return;
        }

        $send = $recorder->sendOf($message, $vonageMessageId);

        if ($send === SmsDeliveryRecorder::SEND_UNKNOWN) {
            // Found by client reference, but recordSent() has not stored this send's ids yet.
            if ($this->retryLookup()) {
                return;
            }

            // Not written to the message: the id is unknown, so the receipt is unverified.
            Log::error('RecordVonageDlrJob: Receipt does not belong to a known send of the message.', ['vonageMessageId' => $vonageMessageId, 'messageId' => $message->getKey()]);

            return;
        }

        if ($send === SmsDeliveryRecorder::SEND_PREVIOUS) {
            // A late receipt of an earlier send never decides the current one.
            $recorder->recordLateEvent($message->getKey(), 'vonage', $status, $vonageMessageId);

            return;
        }

        $meta = [
            'dlr_status' => $status,
            'dlr_err_code' => (int) ($this->data['err-code'] ?? 0),
            'dlr_timestamp' => $this->data['message-timestamp'] ?? null,
            'dlr_price' => $this->data['price'] ?? null,
            'dlr_network_code' => $this->data['network-code'] ?? null,
        ];

        $allPartsDelivered = $recorder->recordPartStatus($message->getKey(), $vonageMessageId, $status, $meta);
        if ($allPartsDelivered === null) {
            // A new send was recorded since the receipt was classified.
            $recorder->recordLateEvent($message->getKey(), 'vonage', $status, $vonageMessageId);

            return;
        }

        $stillApplies = fn (Message $locked): bool => $recorder->sendOf($locked, $vonageMessageId) === SmsDeliveryRecorder::SEND_CURRENT
            && ($status !== 'delivered' || $recorder->allPartsDelivered($locked));

        if ($status === 'delivered') {
            // A multipart SMS counts as delivered only once every part was delivered.
            if (! $allPartsDelivered) {
                return;
            }

            $meta['success'] = true;
            $recorder->recordFinal($message, 'vonage', $status, true, $this->parseEventTimestamp($this->data['message-timestamp'] ?? null), $meta, $stillApplies, $vonageMessageId);

            return;
        }

        if (in_array($status, ['failed', 'rejected', 'expired'], true)) {
            $recorder->recordFinal($message, 'vonage', $status, false, now(), $meta, $stillApplies, $vonageMessageId);

            return;
        }

        // Intermediate status: the details were merged by recordPartStatus() under the lock.
    }

    /**
     * Release the job for another lookup while the receipt may have overtaken
     * the send listener. False once the lookup attempts are used up.
     */
    private function retryLookup(): bool
    {
        if ($this->job === null || $this->attempts() >= self::MAX_LOOKUP_ATTEMPTS) {
            return false;
        }

        $this->release(min(30 * $this->attempts(), 120));

        return true;
    }
}
