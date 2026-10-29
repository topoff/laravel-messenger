<?php

declare(strict_types=1);

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Services\Sms\SmsDeliveryRecorder;

/**
 * Records one AWS End User Messaging SMS event. Only final events
 * (isFinal=true) set delivered_at / failed_at. An event that cannot be
 * matched yet (it overtook the send listener) is retried while the event is
 * younger than MATCH_WINDOW_MINUTES; the window looks at the event's own
 * timestamp, so a matched event is recorded regardless of queue backlog.
 */
class RecordAwsSmsEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int MATCH_WINDOW_MINUTES = 30;

    public const array DELIVERED_EVENTS = ['TEXT_DELIVERED', 'TEXT_SUCCESSFUL'];

    public const array FAILED_EVENTS = [
        'TEXT_BLOCKED', 'TEXT_INVALID', 'TEXT_INVALID_MESSAGE', 'TEXT_CARRIER_BLOCKED', 'TEXT_CARRIER_UNREACHABLE',
        'TEXT_UNREACHABLE', 'TEXT_TTL_EXPIRED', 'TEXT_SPAM', 'TEXT_PROTECT_BLOCKED', 'TEXT_UNKNOWN',
    ];

    public int $tries = 10;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300, 300, 600, 600, 900, 900];

    /** @param  array<string, mixed>  $event  The decoded SNS Message (one SMS event) */
    public function __construct(public array $event) {}

    public function handle(SmsDeliveryRecorder $recorder): void
    {
        $eventType = (string) ($this->event['eventType'] ?? '');
        $isFinal = (bool) ($this->event['isFinal'] ?? false);

        if (! $isFinal || (! in_array($eventType, self::DELIVERED_EVENTS, true) && ! in_array($eventType, self::FAILED_EVENTS, true))) {
            if ($isFinal) {
                Log::warning('RecordAwsSmsEventJob: unknown final event type, ignored.', ['eventType' => $eventType, 'messageId' => $this->event['messageId'] ?? null]);
            }

            return;
        }

        $providerMessageId = isset($this->event['messageId']) ? (string) $this->event['messageId'] : null;
        $messengerMessageId = data_get($this->event, 'context.messenger_message_id');

        $message = $recorder->findMessage(is_scalar($messengerMessageId) ? (string) $messengerMessageId : null, $providerMessageId);

        if (! $message instanceof Message) {
            $this->retryOrDrop($providerMessageId);

            return;
        }

        $eventAt = $this->eventTime();

        $recorder->recordFinal($message, 'aws', $eventType, in_array($eventType, self::DELIVERED_EVENTS, true), $eventAt, [
            'aws_sms_status' => $eventType,
            'aws_sms_status_description' => $this->event['messageStatusDescription'] ?? null,
            'aws_sms_carrier' => $this->event['carrierName'] ?? null,
            'aws_sms_mcc_mnc' => isset($this->event['mcc']) ? ($this->event['mcc'].'/'.($this->event['mnc'] ?? '')) : null,
            'aws_sms_price' => $this->event['totalMessagePrice'] ?? null,
            'aws_sms_parts' => $this->event['totalMessageParts'] ?? null,
        ]);
    }

    private function retryOrDrop(?string $providerMessageId): void
    {
        $eventAt = $this->eventTime();
        $withinWindow = ! $eventAt instanceof Carbon || $eventAt->greaterThan(now()->subMinutes(self::MATCH_WINDOW_MINUTES));

        if ($withinWindow && $this->attempts() < $this->tries) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 60);

            return;
        }

        Log::info('RecordAwsSmsEventJob: no message for SMS event, dropped.', [
            'messageId' => $providerMessageId,
            'eventType' => $this->event['eventType'] ?? null,
            'attempts' => $this->attempts(),
        ]);
    }

    private function eventTime(): ?Carbon
    {
        $timestamp = $this->event['eventTimestamp'] ?? null;

        return is_numeric($timestamp) ? Carbon::createFromTimestampMs((int) $timestamp) : null;
    }
}
