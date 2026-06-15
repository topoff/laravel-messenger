<?php

use Aws\Sqs\SqsClient;
use Topoff\Messenger\Services\SesSns\SnsNotificationProcessor;
use Topoff\Messenger\Services\SesSns\SqsTrackingPoller;

it('processes an sns-enveloped body from sqs', function () {
    $message = createMessage([
        'tracking_message_id' => 'sqs-envelope-mid',
        'tracking_meta' => [],
    ]);

    $body = json_encode([
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => ['messageId' => 'sqs-envelope-mid'],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['receiver@example.com'],
            ],
        ]),
    ]);

    resolve(SqsTrackingPoller::class)->processBody($body);

    $message->refresh();

    expect(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and($message->delivered_at)->not->toBeNull();
});

it('processes a raw-delivery body from sqs', function () {
    $message = createMessage([
        'tracking_message_id' => 'sqs-raw-mid',
        'tracking_meta' => [],
    ]);

    $body = json_encode([
        'notificationType' => 'Bounce',
        'mail' => ['messageId' => 'sqs-raw-mid'],
        'bounce' => [
            'bounceType' => 'Permanent',
            'bounceSubType' => 'General',
            'timestamp' => '2026-01-01T00:05:00Z',
            'bouncedRecipients' => [
                ['emailAddress' => 'receiver@example.com', 'diagnosticCode' => '550 No such user'],
            ],
        ],
    ]);

    resolve(SqsTrackingPoller::class)->processBody($body);

    $message->refresh();

    expect($message->bounced_at)->not->toBeNull()
        ->and(data_get($message->tracking_meta, 'failures.0.emailAddress'))->toBe('receiver@example.com');
});

it('returns invalid body for non-json sqs payloads', function () {
    expect(resolve(SqsTrackingPoller::class)->processBody('not json'))->toBe('invalid body');
});

it('drains messages from the queue and deletes processed ones', function () {
    config()->set('messenger.ses_sns.sqs.queue_url', 'https://sqs.eu-central-1.amazonaws.com/1/queue');

    $message = createMessage([
        'tracking_message_id' => 'sqs-drain-mid',
        'tracking_meta' => [],
    ]);

    $body = json_encode([
        'Type' => 'Notification',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => ['messageId' => 'sqs-drain-mid'],
            'delivery' => [
                'smtpResponse' => '250 Ok',
                'timestamp' => '2026-01-01T00:00:00Z',
                'recipients' => ['receiver@example.com'],
            ],
        ]),
    ]);

    $client = Mockery::mock(SqsClient::class);
    $client->shouldReceive('receiveMessage')->andReturn(
        ['Messages' => [['Body' => $body, 'ReceiptHandle' => 'rh-1']]],
        ['Messages' => []],
    );
    $client->shouldReceive('deleteMessage')
        ->once()
        ->with(Mockery::on(fn (array $args): bool => $args['ReceiptHandle'] === 'rh-1'));

    $poller = resolve(SqsTrackingPoller::class);
    $poller->setClient($client);

    $processed = $poller->poll();

    expect($processed)->toBe(1);

    $message->refresh();
    expect($message->delivered_at)->not->toBeNull();
});

it('leaves a failed message on the queue for redrive to the dlq', function () {
    config()->set('messenger.ses_sns.sqs.queue_url', 'https://sqs.eu-central-1.amazonaws.com/1/queue');

    // A processor that throws simulates a transient processing failure. The
    // message must NOT be deleted so SQS makes it visible again and eventually
    // redrives it to the dead-letter queue.
    $processor = Mockery::mock(SnsNotificationProcessor::class);
    $processor->shouldReceive('processEnvelope')->andThrow(new RuntimeException('boom'));
    app()->instance(SnsNotificationProcessor::class, $processor);

    $client = Mockery::mock(SqsClient::class);
    $client->shouldReceive('receiveMessage')->andReturn(
        ['Messages' => [['Body' => '{"Type":"Notification","Message":"{}"}', 'ReceiptHandle' => 'rh-fail']]],
        ['Messages' => []],
    );
    $client->shouldNotReceive('deleteMessage');

    $poller = resolve(SqsTrackingPoller::class);
    $poller->setClient($client);

    $statuses = [];
    $poller->poll(maxMessages: 1, onMessage: function (string $status) use (&$statuses): void {
        $statuses[] = $status;
    });

    expect($statuses)->toBe(['error']);
});
