<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\SesSns;

use Aws\Sqs\SqsClient;
use RuntimeException;
use Throwable;

/**
 * Drains the SQS queue that SNS fans SES events into and hands each message
 * body to the SnsNotificationProcessor — the same processing path as the HTTP
 * webhook. Used when messenger.tracking.event_transport = 'sqs'.
 */
class SqsTrackingPoller
{
    protected ?SqsClient $client = null;

    public function __construct(protected SnsNotificationProcessor $processor) {}

    /**
     * Drain the queue until it is empty, max messages are processed, or the time
     * budget elapses. Returns the number of messages successfully processed.
     *
     * Failed messages are left on the queue: SQS makes them visible again after
     * the visibility timeout and, once max_receive_count is exceeded, redrives
     * them to the dead-letter queue.
     */
    public function poll(?int $maxMessages = null, ?int $maxSeconds = null, ?callable $onMessage = null): int
    {
        $client = $this->client();
        $queueUrl = $this->queueUrl();
        $maxBatch = max(1, min(10, (int) config('messenger.ses_sns.sqs.poll_max_messages', 10)));
        $waitTime = max(0, min(20, (int) config('messenger.ses_sns.sqs.wait_time_seconds', 20)));
        $visibilityTimeout = max(0, (int) config('messenger.ses_sns.sqs.visibility_timeout', 60));

        $deadline = $maxSeconds !== null ? time() + $maxSeconds : null;
        $processed = 0;

        while (true) {
            if ($maxMessages !== null && $processed >= $maxMessages) {
                break;
            }
            if ($deadline !== null && time() >= $deadline) {
                break;
            }

            $result = $client->receiveMessage([
                'QueueUrl' => $queueUrl,
                'MaxNumberOfMessages' => $maxBatch,
                'WaitTimeSeconds' => $waitTime,
                'VisibilityTimeout' => $visibilityTimeout,
            ]);

            $messages = (array) ($result['Messages'] ?? []);
            if ($messages === []) {
                break;
            }

            foreach ($messages as $message) {
                $status = $this->processMessage($client, $queueUrl, $message);
                $processed++;

                if ($onMessage !== null) {
                    $onMessage($status, $message);
                }

                if ($maxMessages !== null && $processed >= $maxMessages) {
                    break;
                }
            }
        }

        return $processed;
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function processMessage(SqsClient $client, string $queueUrl, array $message): string
    {
        $body = (string) ($message['Body'] ?? '');
        $receiptHandle = (string) ($message['ReceiptHandle'] ?? '');

        try {
            $status = $this->processBody($body);
        } catch (Throwable $e) {
            // Leave the message on the queue so SQS retries / redrives to the DLQ.
            report($e);

            return 'error';
        }

        if ($receiptHandle !== '') {
            $client->deleteMessage([
                'QueueUrl' => $queueUrl,
                'ReceiptHandle' => $receiptHandle,
            ]);
        }

        return $status;
    }

    /**
     * Process a single SQS message body: an SNS envelope JSON, or the raw SES
     * message when raw message delivery is enabled on the subscription.
     */
    public function processBody(string $body): string
    {
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return 'invalid body';
        }

        return $this->processor->processEnvelope($decoded);
    }

    public function queueUrl(): string
    {
        $configured = (string) config('messenger.ses_sns.sqs.queue_url', '');
        if ($configured !== '') {
            return $configured;
        }

        $queueName = (string) config('messenger.ses_sns.sqs.queue_name', '');
        if ($queueName === '') {
            throw new RuntimeException('No SQS queue configured. Set messenger.ses_sns.sqs.queue_url or queue_name.');
        }

        $result = $this->client()->getQueueUrl(['QueueName' => $queueName]);
        $url = (string) ($result['QueueUrl'] ?? '');
        if ($url === '') {
            throw new RuntimeException('Could not resolve SQS queue URL for: '.$queueName);
        }

        return $url;
    }

    public function setClient(SqsClient $client): void
    {
        $this->client = $client;
    }

    protected function client(): SqsClient
    {
        if ($this->client instanceof SqsClient) {
            return $this->client;
        }

        if (! class_exists(SqsClient::class)) {
            throw new RuntimeException('AWS SQS client not found. Please install aws/aws-sdk-php.');
        }

        return $this->client = new SqsClient(AwsClientConfig::shared());
    }
}
