<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\Events\MessageRejectedEvent;

class RecordVonageDlrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    /** @param  array<string, mixed>  $data */
    public function __construct(public array $data) {}

    public function retryUntil(): Carbon
    {
        return Carbon::now()->addDays(5);
    }

    public function handle(): void
    {
        $vonageMessageId = $this->data['messageId'] ?? null;
        if (! $vonageMessageId) {
            Log::warning('RecordVonageDlrJob: Missing messageId in DLR payload');

            return;
        }

        $messageClass = config('messenger.models.message');
        $message = $messageClass::query()
            ->where('tracking_message_id', $vonageMessageId)
            ->first();

        if (! $message) {
            Log::error('RecordVonageDlrJob: No message found for tracking_message_id.', ['vonageMessageId' => $vonageMessageId]);

            return;
        }

        $status = (string) ($this->data['status'] ?? '');

        $meta = collect($message->tracking_meta ?: []);
        $meta->put('dlr_status', $status);
        $meta->put('dlr_err_code', (int) ($this->data['err-code'] ?? 0));
        $meta->put('dlr_timestamp', $this->data['message-timestamp'] ?? null);
        $meta->put('dlr_price', $this->data['price'] ?? null);
        $meta->put('dlr_network_code', $this->data['network-code'] ?? null);

        if ($status === 'delivered') {
            $meta->put('success', true);
            $meta->put('delivered_at', $this->data['message-timestamp'] ?? now()->toIso8601String());
        }

        $message->tracking_meta = $meta->toArray();

        $permanentFailureStatuses = ['failed', 'rejected', 'expired'];

        if (in_array($status, $permanentFailureStatuses, true)) {
            $message->failed_at = now();
        }

        $message->save();

        if ($status === 'delivered') {
            Event::dispatch(new MessageDeliveredEvent($message));
        }

        if (in_array($status, $permanentFailureStatuses, true)) {
            Event::dispatch(new MessageRejectedEvent($status, $message));
        }
    }
}
