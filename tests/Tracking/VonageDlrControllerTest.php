<?php

use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\Events\MessageRejectedEvent;

beforeEach(function () {
    config()->set('messenger.tracking.vonage_dlr.enabled', true);
});

it('processes delivered DLR and dispatches event', function () {
    Event::fake([MessageDeliveredEvent::class]);

    $message = createMessage([
        'tracking_message_id' => 'vonage-dlr-001',
        'tracking_recipient_contact' => '+41791234567',
        'tracking_meta' => [],
    ]);

    $this->postJson(route('messenger.tracking.vonage-dlr'), [
        'messageId' => 'vonage-dlr-001',
        'status' => 'delivered',
        'err-code' => '0',
        'message-timestamp' => '2026-03-01 12:00:00',
        'price' => '0.0354',
        'network-code' => '22801',
    ])->assertOk();

    $message->refresh();

    expect(data_get($message->tracking_meta, 'dlr_status'))->toBe('delivered')
        ->and(data_get($message->tracking_meta, 'success'))->toBeTrue()
        ->and($message->delivered_at?->toDateTimeString())->toBe('2026-03-01 12:00:00');

    Event::assertDispatched(MessageDeliveredEvent::class, fn (MessageDeliveredEvent $e): bool => $e->message->id === $message->id);
});

it('sets failed_at for failed status and dispatches rejected event', function () {
    Event::fake([MessageRejectedEvent::class]);

    $message = createMessage([
        'tracking_message_id' => 'vonage-dlr-002',
        'tracking_meta' => [],
    ]);

    $this->postJson(route('messenger.tracking.vonage-dlr'), [
        'messageId' => 'vonage-dlr-002',
        'status' => 'failed',
        'err-code' => '1',
    ])->assertOk();

    $message->refresh();

    expect($message->failed_at)->not->toBeNull()
        ->and(data_get($message->tracking_meta, 'dlr_status'))->toBe('failed');

    Event::assertDispatched(MessageRejectedEvent::class, fn (MessageRejectedEvent $e): bool => $e->reason === 'failed'
        && $e->message->id === $message->id);
});

it('sets failed_at for rejected status', function () {
    $message = createMessage([
        'tracking_message_id' => 'vonage-dlr-003',
        'tracking_meta' => [],
    ]);

    $this->postJson(route('messenger.tracking.vonage-dlr'), [
        'messageId' => 'vonage-dlr-003',
        'status' => 'rejected',
        'err-code' => '6',
    ])->assertOk();

    $message->refresh();

    expect($message->failed_at)->not->toBeNull()
        ->and(data_get($message->tracking_meta, 'dlr_status'))->toBe('rejected');
});

it('sets failed_at for expired status', function () {
    $message = createMessage([
        'tracking_message_id' => 'vonage-dlr-004',
        'tracking_meta' => [],
    ]);

    $this->postJson(route('messenger.tracking.vonage-dlr'), [
        'messageId' => 'vonage-dlr-004',
        'status' => 'expired',
        'err-code' => '3',
    ])->assertOk();

    $message->refresh();

    expect($message->failed_at)->not->toBeNull()
        ->and(data_get($message->tracking_meta, 'dlr_status'))->toBe('expired');
});

it('returns disabled when vonage_dlr is disabled', function () {
    config()->set('messenger.tracking.vonage_dlr.enabled', false);

    $this->postJson(route('messenger.tracking.vonage-dlr'), [
        'messageId' => 'vonage-dlr-disabled',
        'status' => 'delivered',
    ])->assertOk()->assertSee('vonage dlr disabled');
});

it('returns empty payload when no data', function () {
    $this->getJson(route('messenger.tracking.vonage-dlr'))
        ->assertOk()
        ->assertSee('empty payload');
});

it('ignores DLR for unknown message', function () {
    $this->postJson(route('messenger.tracking.vonage-dlr'), [
        'messageId' => 'nonexistent-message-id',
        'status' => 'delivered',
    ])->assertOk();
});
