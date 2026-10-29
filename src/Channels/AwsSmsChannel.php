<?php

declare(strict_types=1);

namespace Topoff\Messenger\Channels;

use Aws\PinpointSMSVoiceV2\PinpointSMSVoiceV2Client;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * Notification channel `aws-sms`: sends through AWS End User Messaging SMS
 * (SMS-Voice v2 API). Notifications implement toAwsSms(); the recipient comes
 * from routeNotificationFor('aws-sms'). Delivery events arrive asynchronously
 * through the configured configuration set (see AwsSmsEventsController).
 *
 * Credentials come only from messenger.sms.aws — never the SDK default chain,
 * which would silently pick up unrelated keys (e.g. SES). SDK retries are off:
 * SendTextMessage has no idempotency token, so an SDK-internal retry after an
 * accepted but lost response would send the SMS twice.
 */
class AwsSmsChannel
{
    public function __construct(private ?PinpointSMSVoiceV2Client $client = null) {}

    public function send(object $notifiable, Notification $notification): ?AwsSmsSentMessage
    {
        $to = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('aws-sms', $notification)
            : null;

        if (! is_string($to) || $to === '') {
            return null;
        }

        if (! method_exists($notification, 'toAwsSms')) {
            throw new RuntimeException($notification::class.' does not implement toAwsSms().');
        }

        $message = $notification->toAwsSms($notifiable);
        $content = $message instanceof AwsSmsMessage ? $message->content : (string) $message;

        $configurationSet = (string) config('messenger.sms.aws.configuration_set');
        $messengerMessageId = $notification->messengerMessageId ?? null;

        $result = $this->client()->sendTextMessage([
            'DestinationPhoneNumber' => $to,
            'OriginationIdentity' => (string) config('messenger.sms.aws.origination_identity'),
            'MessageBody' => $content,
            'MessageType' => (string) config('messenger.sms.aws.message_type', 'TRANSACTIONAL'),
            ...($configurationSet !== '' ? ['ConfigurationSetName' => $configurationSet] : []),
            ...($messengerMessageId ? ['Context' => ['messenger_message_id' => (string) $messengerMessageId]] : []),
        ]);

        $messageId = $result->get('MessageId');
        if (! is_string($messageId) || $messageId === '') {
            throw new RuntimeException('AWS SendTextMessage returned no MessageId.');
        }

        return new AwsSmsSentMessage($messageId, $to);
    }

    private function client(): PinpointSMSVoiceV2Client
    {
        if ($this->client instanceof PinpointSMSVoiceV2Client) {
            return $this->client;
        }

        $key = (string) config('messenger.sms.aws.key');
        $secret = (string) config('messenger.sms.aws.secret');
        if ($key === '' || $secret === '') {
            throw new RuntimeException('AWS SMS credentials are not configured (messenger.sms.aws.key / secret).');
        }

        return $this->client = new PinpointSMSVoiceV2Client([
            'version' => 'latest',
            'region' => (string) config('messenger.sms.aws.region', 'eu-central-2'),
            'credentials' => ['key' => $key, 'secret' => $secret],
            'retries' => 0,
        ]);
    }
}
