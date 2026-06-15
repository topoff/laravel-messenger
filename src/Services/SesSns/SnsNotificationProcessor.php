<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\SesSns;

use Illuminate\Support\Facades\Http;
use Topoff\Messenger\Events\SesSnsWebhookReceivedEvent;
use Topoff\Messenger\Jobs\RecordBounceJob;
use Topoff\Messenger\Jobs\RecordComplaintJob;
use Topoff\Messenger\Jobs\RecordDeliveryJob;
use Topoff\Messenger\Jobs\RecordRejectJob;

/**
 * Transport-agnostic processing of SES event notifications delivered via SNS.
 *
 * Both the HTTP webhook (MailTrackingSnsController) and the SQS poller
 * (SqsTrackingPoller) funnel raw payloads through this service so the
 * envelope parsing, topic verification, test-detection and job routing
 * live in exactly one place.
 */
class SnsNotificationProcessor
{
    /**
     * Process a raw SNS envelope as received over HTTP, or the SNS envelope
     * wrapped inside an SQS message body. Raw-message-delivery payloads (no
     * `Type` field, SES message at the top level) are detected and handled too.
     *
     * @param  array<string, mixed>  $payload
     */
    public function processEnvelope(array $payload): string
    {
        $type = $payload['Type'] ?? null;

        if ($type === 'SubscriptionConfirmation') {
            $subscribeUrl = $payload['SubscribeURL'] ?? null;
            if (is_string($subscribeUrl) && $subscribeUrl !== '') {
                Http::get($subscribeUrl);
            }

            return 'subscription confirmed';
        }

        // SNS -> SQS with raw message delivery enabled (or any caller that
        // already unwrapped the envelope) hands us the SES message directly.
        if ($type === null && $this->looksLikeSesMessage($payload)) {
            return $this->processSesMessage($payload, $payload);
        }

        if ($type !== 'Notification' || ! isset($payload['Message'])) {
            return 'invalid payload';
        }

        $message = is_array($payload['Message'])
            ? $payload['Message']
            : (json_decode((string) $payload['Message'], true) ?: []);

        $expectedTopic = config('messenger.tracking.sns_topic');
        if ($expectedTopic && ($payload['TopicArn'] ?? null) !== $expectedTopic) {
            return 'invalid topic ARN';
        }

        return $this->processSesMessage($message, $payload);
    }

    /**
     * Process a decoded SES event message (the contents of the SNS `Message`
     * field, or a raw-delivery body).
     *
     * @param  array<string, mixed>  $message
     * @param  array<string, mixed>|null  $envelope  The raw SNS envelope, for the webhook event. Defaults to the message itself.
     */
    public function processSesMessage(array $message, ?array $envelope = null): string
    {
        $notificationType = $message['notificationType'] ?? $message['eventType'] ?? null;
        $processSynchronously = $this->isMessengerTestNotification($message);

        event(new SesSnsWebhookReceivedEvent($envelope ?? $message, $message, $notificationType, $processSynchronously));

        match ($notificationType) {
            'Delivery' => $this->dispatchTrackingJob(RecordDeliveryJob::class, $message, $processSynchronously),
            'Bounce' => $this->dispatchTrackingJob(RecordBounceJob::class, $message, $processSynchronously),
            'Complaint' => $this->dispatchTrackingJob(RecordComplaintJob::class, $message, $processSynchronously),
            'Reject' => $this->dispatchTrackingJob(RecordRejectJob::class, $message, $processSynchronously),
            default => null,
        };

        return 'notification processed';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function looksLikeSesMessage(array $message): bool
    {
        return isset($message['notificationType']) || isset($message['eventType']) || isset($message['mail']);
    }

    /**
     * @param  class-string  $jobClass
     * @param  array<string, mixed>  $message
     */
    protected function dispatchTrackingJob(string $jobClass, array $message, bool $processSynchronously): void
    {
        if ($processSynchronously) {
            new $jobClass($message)->handle();

            return;
        }

        $jobClass::dispatch($message)->onQueue(config('messenger.tracking.tracker_queue'));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    public function isMessengerTestNotification(array $message): bool
    {
        $mailManagerTestTag = $this->extractMailTagValue($message, 'messenger_test');
        if ($mailManagerTestTag !== null) {
            return in_array(strtolower($mailManagerTestTag), ['1', 'true', 'yes'], true);
        }

        $subject = (string) data_get($message, 'mail.commonHeaders.subject', '');

        return str_starts_with($subject, '[messenger][');
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function extractMailTagValue(array $message, string $tagName): ?string
    {
        $tags = (array) data_get($message, 'mail.tags', []);

        // SES SNS payload often provides tags as a map: {"tagName": ["value"]}.
        if (array_is_list($tags)) {
            foreach ($tags as $tag) {
                if (! is_array($tag)) {
                    continue;
                }

                $name = (string) ($tag['name'] ?? $tag['Name'] ?? $tag['key'] ?? $tag['Key'] ?? '');
                if (strtolower($name) !== strtolower($tagName)) {
                    continue;
                }

                $value = (string) ($tag['value'] ?? $tag['Value'] ?? '');

                return $value !== '' ? $value : null;
            }

            return null;
        }

        foreach ($tags as $name => $values) {
            if (strtolower((string) $name) !== strtolower($tagName)) {
                continue;
            }

            $firstValue = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;

            return $firstValue !== '' ? $firstValue : null;
        }

        return null;
    }
}
