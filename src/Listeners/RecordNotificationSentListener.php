<?php

namespace Topoff\Messenger\Listeners;

use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;
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
            if (! is_object($response) || ! ($response instanceof \Traversable)) {
                Log::error('RecordNotificationSentListener: SMS response is not traversable.', [
                    'messengerMessageId' => $messengerMessageId,
                    'channel' => $event->channel,
                    'response_type' => get_debug_type($response),
                ]);

                return;
            }

            /** @var object|null $sentSms */
            $sentSms = null;
            foreach ($response as $item) {
                $sentSms = $item;
                break;
            }

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
}
