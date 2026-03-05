<?php

use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\Events\MessageRejectedEvent;
use Topoff\Messenger\Jobs\RecordVonageDlrJob;

it('updates tracking_meta on delivered', function () {
    $message = createMessage([
        'tracking_message_id' => 'vonage-job-001',
        'tracking_meta' => [],
    ]);

    $job = new RecordVonageDlrJob([
        'messageId' => 'vonage-job-001',
        'status' => 'delivered',
        'err-code' => '0',
        'message-timestamp' => '2026-03-01 12:00:00',
        'price' => '0.0354',
        'network-code' => '22801',
    ]);

    $job->handle();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'dlr_status'))->toBe('delivered')
        ->and(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and(data_get($message->tracking_meta, 'delivered_at'))->toBe('2026-03-01 12:00:00')
        ->and(data_get($message->tracking_meta, 'dlr_err_code'))->toBe(0)
        ->and(data_get($message->tracking_meta, 'dlr_price'))->toBe('0.0354')
        ->and(data_get($message->tracking_meta, 'dlr_network_code'))->toBe('22801');
});

it('dispatches MessageDeliveredEvent on delivered', function () {
    Event::fake([MessageDeliveredEvent::class]);

    $message = createMessage([
        'tracking_message_id' => 'vonage-job-002',
        'tracking_recipient_contact' => '+41791234567',
        'tracking_meta' => [],
    ]);

    $job = new RecordVonageDlrJob([
        'messageId' => 'vonage-job-002',
        'status' => 'delivered',
    ]);

    $job->handle();

    Event::assertDispatched(MessageDeliveredEvent::class, fn (MessageDeliveredEvent $e): bool => $e->message->id === $message->id);
});

it('sets failed_at and dispatches MessageRejectedEvent on failed', function () {
    Event::fake([MessageRejectedEvent::class]);

    $message = createMessage([
        'tracking_message_id' => 'vonage-job-003',
        'tracking_meta' => [],
    ]);

    $job = new RecordVonageDlrJob([
        'messageId' => 'vonage-job-003',
        'status' => 'failed',
        'err-code' => '1',
    ]);

    $job->handle();

    $message->refresh();

    expect($message->failed_at)->not->toBeNull()
        ->and(data_get($message->tracking_meta, 'dlr_status'))->toBe('failed');

    Event::assertDispatched(MessageRejectedEvent::class, fn (MessageRejectedEvent $e): bool => $e->reason === 'failed'
        && $e->message->id === $message->id);
});

it('skips when messageId is missing', function () {
    $job = new RecordVonageDlrJob([
        'status' => 'delivered',
    ]);

    $job->handle();

    // No exception thrown — just exits gracefully
    expect(true)->toBeTrue();
});

it('skips when no matching message found', function () {
    $job = new RecordVonageDlrJob([
        'messageId' => 'nonexistent-id',
        'status' => 'delivered',
    ]);

    $job->handle();

    // No exception thrown — just exits gracefully
    expect(true)->toBeTrue();
});
