<?php

use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\SesSnsWebhookReceivedEvent;
use Topoff\Messenger\Services\SesSns\SnsNotificationProcessor;

it('processes a raw ses message delivered without an sns envelope', function () {
    Event::fake([SesSnsWebhookReceivedEvent::class]);

    $message = createMessage([
        'tracking_message_id' => 'raw-delivery-mid',
        'tracking_meta' => [],
    ]);

    // SNS -> SQS with raw message delivery enabled hands the SES message directly.
    resolve(SnsNotificationProcessor::class)->processEnvelope([
        'notificationType' => 'Delivery',
        'mail' => ['messageId' => 'raw-delivery-mid'],
        'delivery' => [
            'smtpResponse' => '250 Ok',
            'timestamp' => '2026-01-01T00:00:00Z',
            'recipients' => ['receiver@example.com'],
        ],
    ]);

    $message->refresh();

    expect(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and($message->delivered_at)->not->toBeNull();

    Event::assertDispatched(SesSnsWebhookReceivedEvent::class, fn (SesSnsWebhookReceivedEvent $event): bool => $event->notificationType === 'Delivery');
});

it('returns invalid payload for a non-notification envelope', function () {
    $status = resolve(SnsNotificationProcessor::class)->processEnvelope([
        'Type' => 'Notification',
    ]);

    expect($status)->toBe('invalid payload');
});

it('rejects a notification whose topic arn does not match the configured topic', function () {
    config()->set('messenger.tracking.sns_topic', 'arn:aws:sns:eu-central-1:1:expected');

    $message = createMessage([
        'tracking_message_id' => 'wrong-topic-mid',
        'tracking_meta' => [],
    ]);

    $status = resolve(SnsNotificationProcessor::class)->processEnvelope([
        'Type' => 'Notification',
        'TopicArn' => 'arn:aws:sns:eu-central-1:1:other',
        'Message' => json_encode([
            'notificationType' => 'Delivery',
            'mail' => ['messageId' => 'wrong-topic-mid'],
            'delivery' => ['recipients' => ['receiver@example.com']],
        ]),
    ]);

    $message->refresh();

    expect($status)->toBe('invalid topic ARN')
        ->and($message->tracking_meta)->toBeEmpty();
});
