<?php

namespace Topoff\Messenger\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;
use Topoff\Messenger\Services\Sms\SmsClientReference;
use Topoff\Messenger\Services\Sms\SmsDeliveryRecorder;

/**
 * Stores the provider message id of an SMS sent through a tracked channel
 * (messenger.tracking.sms_channels) so delivery receipts can be correlated.
 * The default list lives in code because host apps that override the whole
 * `tracking` config block would otherwise lose Vonage tracking.
 */
class RecordNotificationSentListener
{
    /** @var array<string, string> channel => provider key stored in tracking_meta.provider */
    private const array PROVIDERS = ['vonage' => 'vonage', 'aws-sms' => 'aws'];

    private readonly SmsDeliveryRecorder $recorder;

    public function __construct(?SmsDeliveryRecorder $recorder = null)
    {
        $this->recorder = $recorder ?? app(SmsDeliveryRecorder::class);
    }

    public function handle(NotificationSent $event): void
    {
        try {
            $messengerMessageId = $event->notification->messengerMessageId ?? null;
            if (! $messengerMessageId) {
                return;
            }

            $trackedChannels = (array) config('messenger.tracking.sms_channels', array_keys(self::PROVIDERS));
            if (! in_array($event->channel, $trackedChannels, true)) {
                return;
            }

            $response = $event->response;
            if ($response === null) {
                // The channel sent nothing (e.g. Laravel's Vonage channel without a recipient route).
                Log::warning('RecordNotificationSentListener: SMS channel returned no response, no SMS was sent (no recipient route).', [
                    'messengerMessageId' => $messengerMessageId,
                    'channel' => $event->channel,
                ]);

                return;
            }

            if (! is_object($response) || ! ($response instanceof \Traversable)) {
                Log::error('RecordNotificationSentListener: SMS response is not traversable.', [
                    'messengerMessageId' => $messengerMessageId,
                    'channel' => $event->channel,
                    'response_type' => get_debug_type($response),
                ]);

                return;
            }

            // A multipart SMS comes back as one item per part, each with its own provider id.
            $items = [];
            foreach ($response as $item) {
                $items[] = $item;
            }

            /** @var object|null $sentSms */
            $sentSms = $items[0] ?? null;

            if (! $sentSms || ! method_exists($sentSms, 'getMessageId')) {
                Log::error('RecordNotificationSentListener: SMS response item missing getMessageId method.', [
                    'messengerMessageId' => $messengerMessageId,
                    'channel' => $event->channel,
                    'item_type' => $sentSms ? $sentSms::class : 'null',
                ]);

                return;
            }

            $meta = ['provider' => self::PROVIDERS[$event->channel] ?? $event->channel];
            if (method_exists($sentSms, 'getStatus')) {
                $meta['vonage_status'] = (string) $sentSms->getStatus();
            }
            if (method_exists($sentSms, 'getNetwork')) {
                $meta['vonage_network'] = $sentSms->getNetwork();
            }
            if (method_exists($sentSms, 'getPrice')) {
                $meta['vonage_message_price'] = $sentSms->getPrice();
            }

            $partIds = $this->partIdsMatchableByClientReference($items, $sentSms, $messengerMessageId);
            if ($partIds !== []) {
                $meta['sms_part_ids'] = $partIds;
            }

            $messageClass = config('messenger.models.message');
            if (! $messageClass::query()->whereKey($messengerMessageId)->exists()) {
                Log::error('RecordNotificationSentListener: Message not found.', ['messengerMessageId' => $messengerMessageId]);

                return;
            }

            $this->recorder->recordSent(
                $messengerMessageId,
                (string) $sentSms->getMessageId(),
                method_exists($sentSms, 'getTo') ? $sentSms->getTo() : null,
                $meta,
            );
        } catch (Throwable $t) {
            Log::warning('RecordNotificationSentListener: Failed to record SMS response', [
                'error' => $t->getMessage(),
                'messengerMessageId' => $event->notification->messengerMessageId ?? null,
            ]);
        }
    }

    /**
     * The provider ids of all parts — only for a multipart SMS that carried the
     * messenger's own client reference: only then do the receipts of the later
     * parts find this message, so only then may the final state wait for them.
     *
     * @param  list<mixed>  $items
     * @return list<string>
     */
    private function partIdsMatchableByClientReference(array $items, object $sentSms, int|string $messengerMessageId): array
    {
        if (count($items) < 2 || ! method_exists($sentSms, 'getClientRef')) {
            return [];
        }

        $expectedReference = SmsClientReference::for($messengerMessageId);
        if ($expectedReference === '' || $sentSms->getClientRef() !== $expectedReference) {
            return [];
        }

        $partIds = [];
        foreach ($items as $item) {
            if (! is_object($item) || ! method_exists($item, 'getMessageId')) {
                return [];
            }

            $partIds[] = (string) $item->getMessageId();
        }

        return array_values(array_unique($partIds));
    }
}
