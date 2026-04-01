<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Topoff\Messenger\Jobs\RecordBounceJob;
use Topoff\Messenger\Jobs\RecordComplaintJob;
use Topoff\Messenger\Jobs\RecordDeliveryJob;
use Topoff\Messenger\Jobs\RecordRejectJob;

it('releases delivery job back to queue when message is not yet in database', function () {
    Queue::fake();
    Log::spy();

    $snsMessage = [
        'notificationType' => 'Delivery',
        'mail' => ['messageId' => 'not-yet-committed-mid'],
        'delivery' => [
            'smtpResponse' => '250 Ok',
            'timestamp' => '2026-01-01T00:00:00Z',
            'recipients' => ['receiver@example.com'],
        ],
    ];

    $job = new RecordDeliveryJob($snsMessage);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $msg): bool => str_contains($msg, 'RecordDeliveryJob: No message found for tracking_message_id, retrying'));
});

it('releases bounce job back to queue when message is not yet in database', function () {
    Queue::fake();
    Log::spy();

    $snsMessage = [
        'notificationType' => 'Bounce',
        'mail' => ['messageId' => 'not-yet-committed-mid'],
        'bounce' => [
            'bounceType' => 'Permanent',
            'bouncedRecipients' => [['emailAddress' => 'receiver@example.com']],
        ],
    ];

    $job = new RecordBounceJob($snsMessage);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased();
});

it('releases complaint job back to queue when message is not yet in database', function () {
    Queue::fake();
    Log::spy();

    $snsMessage = [
        'notificationType' => 'Complaint',
        'mail' => ['messageId' => 'not-yet-committed-mid'],
        'complaint' => [
            'timestamp' => '2026-01-01T01:00:00Z',
            'complainedRecipients' => [['emailAddress' => 'receiver@example.com']],
        ],
    ];

    $job = new RecordComplaintJob($snsMessage);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased();
});

it('releases reject job back to queue when message is not yet in database', function () {
    Queue::fake();
    Log::spy();

    $snsMessage = [
        'notificationType' => 'Reject',
        'mail' => ['messageId' => 'not-yet-committed-mid'],
        'reject' => ['reason' => 'Bad content'],
    ];

    $job = new RecordRejectJob($snsMessage);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased();
});

it('logs error and gives up after max retry attempts', function () {
    Log::spy();

    $snsMessage = [
        'notificationType' => 'Delivery',
        'mail' => ['messageId' => 'permanently-missing-mid'],
        'delivery' => [
            'smtpResponse' => '250 Ok',
            'timestamp' => '2026-01-01T00:00:00Z',
            'recipients' => ['receiver@example.com'],
        ],
    ];

    $job = new RecordDeliveryJob($snsMessage);
    $job->withFakeQueueInteractions();
    $job->job->attempts = 8;

    $job->handle();

    $job->assertNotReleased();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $msg): bool => str_contains($msg, 'No message found for tracking_message_id after 8 attempts'));
});

it('processes delivery successfully when message exists in database', function () {
    $message = createMessage([
        'tracking_message_id' => 'existing-mid',
        'tracking_meta' => [],
    ]);

    $snsMessage = [
        'notificationType' => 'Delivery',
        'mail' => ['messageId' => 'existing-mid'],
        'delivery' => [
            'smtpResponse' => '250 Ok',
            'timestamp' => '2026-01-01T00:00:00Z',
            'recipients' => ['receiver@example.com'],
        ],
    ];

    $job = new RecordDeliveryJob($snsMessage);
    $job->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotReleased();

    $message->refresh();
    expect(data_get($message->tracking_meta, 'success'))->toBeTrue();
});
