<?php

declare(strict_types=1);

namespace Topoff\Messenger\Http\Controllers;

use Aws\Sns\Message as SnsMessage;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;
use Topoff\Messenger\Exceptions\SnsCertificateUnavailableException;
use Topoff\Messenger\Jobs\RecordAwsSmsEventJob;

/**
 * SNS endpoint for AWS End User Messaging SMS events (configuration set →
 * SNS topic → HTTPS). Separate from the SES endpoint and stricter: it only
 * runs with an explicit topic ARN, always verifies the SNS signature (fail
 * closed when the validator package is missing) and only confirms a
 * subscription for the configured topic, reporting a failed confirmation so
 * SNS retries it.
 */
class AwsSmsEventsController extends Controller
{
    public function callback(Request $request): Response
    {
        $topicArn = (string) config('messenger.tracking.aws_sms_events.topic_arn');
        if (! (bool) config('messenger.tracking.aws_sms_events.enabled', false) || $topicArn === '') {
            return response('aws sms events disabled', 404);
        }

        if (! $this->validatorInstalled()) {
            Log::error('AwsSmsEventsController: aws/aws-php-sns-message-validator is not installed; refusing SMS events.');

            return response('signature validator missing', 503);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (! is_array($payload) || $payload === []) {
            return response('invalid payload', 400);
        }

        try {
            $this->validator()->validate(new SnsMessage($payload));
        } catch (SnsCertificateUnavailableException $e) {
            // Transient: answer 5xx so SNS retries instead of dropping the event.
            Log::warning('AwsSmsEventsController: SNS signing certificate unavailable.', ['error' => $e->getMessage()]);

            return response('certificate unavailable', 503);
        } catch (Throwable) {
            return response('invalid signature', 403);
        }

        if (($payload['TopicArn'] ?? null) !== $topicArn) {
            return response('invalid topic ARN', 403);
        }

        return match ($payload['Type'] ?? null) {
            'SubscriptionConfirmation' => $this->confirmSubscription($payload),
            'Notification' => $this->dispatchEvent($payload),
            default => response('ignored', 200),
        };
    }

    /**
     * The SNS validator with a certificate client that caches the signing
     * certificate and reports fetch failures separately. The validator checks
     * the certificate URL host (sns.<region>.amazonaws.com) before fetching.
     */
    protected function validator(): MessageValidator
    {
        if (app()->bound(MessageValidator::class)) {
            return app(MessageValidator::class);
        }

        return new MessageValidator(function (string $certUrl): string {
            try {
                return Cache::remember('messenger:sns-cert:'.sha1($certUrl), now()->addDay(), fn (): string => $this->fetchCertificate($certUrl));
            } catch (SnsCertificateUnavailableException $e) {
                throw $e;
            } catch (Throwable) {
                // Cache store unavailable: fetch directly instead of rejecting a valid message.
                return $this->fetchCertificate($certUrl);
            }
        });
    }

    private function fetchCertificate(string $certUrl): string
    {
        try {
            return Http::timeout(10)->get($certUrl)->throw()->body();
        } catch (Throwable $t) {
            throw new SnsCertificateUnavailableException($t->getMessage(), 0, $t);
        }
    }

    protected function validatorInstalled(): bool
    {
        return class_exists(MessageValidator::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function confirmSubscription(array $payload): Response
    {
        $subscribeUrl = $payload['SubscribeURL'] ?? null;
        if (! is_string($subscribeUrl) || $subscribeUrl === '') {
            return response('missing SubscribeURL', 400);
        }

        try {
            $confirmation = Http::timeout(10)->get($subscribeUrl);
        } catch (Throwable $t) {
            Log::warning('AwsSmsEventsController: subscription confirmation failed.', ['error' => $t->getMessage()]);

            return response('subscription confirmation failed', 502);
        }

        if (! $confirmation->successful()) {
            Log::warning('AwsSmsEventsController: subscription confirmation failed.', ['status' => $confirmation->status()]);

            return response('subscription confirmation failed', 502);
        }

        return response('subscription confirmed', 200);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatchEvent(array $payload): Response
    {
        $event = is_array($payload['Message'] ?? null)
            ? $payload['Message']
            : json_decode((string) ($payload['Message'] ?? ''), true);

        if (! is_array($event) || ! isset($event['eventType'])) {
            return response('invalid event', 400);
        }

        RecordAwsSmsEventJob::dispatch($event)
            ->onQueue(config('messenger.tracking.tracker_queue'));

        return response('ok', 200);
    }
}
