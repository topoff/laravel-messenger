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

        $message = $recorder->findMessage(null, (string) $vonageMessageId);

        if (! $message) {
            if ($this->job !== null && $this->attempts() < self::MAX_LOOKUP_ATTEMPTS) {
                $this->release(min(30 * $this->attempts(), 120));

                return;
            }

            Log::error('RecordVonageDlrJob: No message found for tracking_message_id.', ['vonageMessageId' => $vonageMessageId]);

            return;
        }

        $status = (string) ($this->data['status'] ?? '');

        $meta = [
            'dlr_status' => $status,
            'dlr_err_code' => (int) ($this->data['err-code'] ?? 0),
            'dlr_timestamp' => $this->data['message-timestamp'] ?? null,
            'dlr_price' => $this->data['price'] ?? null,
            'dlr_network_code' => $this->data['network-code'] ?? null,
        ];

        if ($status === 'delivered') {
            $meta['success'] = true;
            $recorder->recordFinal($message, 'vonage', $status, true, $this->parseEventTimestamp($this->data['message-timestamp'] ?? null), $meta);

            return;
        }

        if (in_array($status, ['failed', 'rejected', 'expired'], true)) {
            $recorder->recordFinal($message, 'vonage', $status, false, now(), $meta);

            return;
        }

        $recorder->mergeMeta($message->getKey(), $meta);
    }
}
