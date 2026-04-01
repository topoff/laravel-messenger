<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Topoff\Messenger\Listeners\RecordNotificationSentListener;

beforeEach(function () {
    $this->listener = new RecordNotificationSentListener;
});

it('records vonage response on messenger notification', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notifiable->route('vonage', '+41791234567');

    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $sentSms = new class
    {
        public function getMessageId(): string
        {
            return 'vonage-msg-001';
        }

        public function getTo(): string
        {
            return '+41791234567';
        }

        public function getStatus(): int
        {
            return 0;
        }

        public function getNetwork(): string
        {
            return '22801';
        }

        public function getPrice(): string
        {
            return '0.0354';
        }
    };

    $response = new readonly class($sentSms) implements IteratorAggregate
    {
        public function __construct(private object $item) {}

        public function getIterator(): ArrayIterator
        {
            return new ArrayIterator([$this->item]);
        }
    };

    $event = new NotificationSent($notifiable, $notification, 'vonage', $response);

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBe('vonage-msg-001')
        ->and($message->tracking_recipient_contact)->toBe('+41791234567')
        ->and(data_get($message->tracking_meta, 'vonage_status'))->toBe('0')
        ->and(data_get($message->tracking_meta, 'vonage_network'))->toBe('22801')
        ->and(data_get($message->tracking_meta, 'vonage_message_price'))->toBe('0.0354');
});

it('skips non-vonage channels', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $event = new NotificationSent($notifiable, $notification, 'mail');

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBeNull();
});

it('skips notifications without messengerMessageId', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notification = new class extends Notification {};

    $event = new NotificationSent($notifiable, $notification, 'vonage');

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBeNull();
});

it('skips when response is not traversable', function () {
    $message = createMessage(['tracking_meta' => []]);

    $notifiable = new AnonymousNotifiable;
    $notification = new class extends Notification {};
    $notification->messengerMessageId = $message->id;

    $event = new NotificationSent($notifiable, $notification, 'vonage', 'plain-string-response');

    $this->listener->handle($event);

    $message->refresh();

    expect($message->tracking_message_id)->toBeNull();
});
