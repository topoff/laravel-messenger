<?php

namespace Topoff\MailManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Topoff\MailManager\Events\SesSnsWebhookReceivedEvent;
use Topoff\MailManager\Jobs\RecordBounceJob;
use Topoff\MailManager\Jobs\RecordComplaintJob;
use Topoff\MailManager\Jobs\RecordDeliveryJob;
use Topoff\MailManager\Jobs\RecordRejectJob;

class MailTrackingSnsController extends Controller
{
    public function callback(Request $request): string
    {
        $payload = $request->json()->all();
        if ($payload === []) {
            $payload = json_decode((string) $request->getContent(), true) ?: [];
        }

        $type = $payload['Type'] ?? null;

        if ($type === 'SubscriptionConfirmation') {
            $subscribeUrl = $payload['SubscribeURL'] ?? null;
            if ($subscribeUrl) {
                Http::get($subscribeUrl);
            }

            return 'subscription confirmed';
        }

        if ($type !== 'Notification' || ! isset($payload['Message'])) {
            return 'invalid payload';
        }

        $message = is_array($payload['Message']) ? $payload['Message'] : (json_decode((string) $payload['Message'], true) ?: []);
        if (config('mail-manager.tracking.sns_topic') && ($payload['TopicArn'] ?? null) !== config('mail-manager.tracking.sns_topic')) {
            return 'invalid topic ARN';
        }

        $notificationType = $message['notificationType'] ?? $message['eventType'] ?? null;
        $processSynchronously = $this->isMailManagerTestNotification($message);
        event(new SesSnsWebhookReceivedEvent($payload, $message, $notificationType, $processSynchronously));

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
     * @param  class-string  $jobClass
     * @param  array<string, mixed>  $message
     */
    protected function dispatchTrackingJob(string $jobClass, array $message, bool $processSynchronously): void
    {
        if ($processSynchronously) {
            (new $jobClass($message))->handle();

            return;
        }

        $jobClass::dispatch($message)->onQueue(config('mail-manager.tracking.tracker_queue'));
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function isMailManagerTestNotification(array $message): bool
    {
        $mailManagerTestTag = $this->extractMailTagValue($message, 'mail_manager_test');
        if ($mailManagerTestTag !== null) {
            return in_array(strtolower($mailManagerTestTag), ['1', 'true', 'yes'], true);
        }

        $subject = (string) data_get($message, 'mail.commonHeaders.subject', '');

        return str_starts_with($subject, '[mail-manager][');
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
