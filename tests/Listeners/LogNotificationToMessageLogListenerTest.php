<?php

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Topoff\Messenger\Listeners\LogNotificationToMessageLogListener;
use Topoff\Messenger\Models\MessageLog;

beforeEach(function () {
    $this->listener = new LogNotificationToMessageLogListener;
});

it('writes a row with the correct channel and populates notification-specific fields', function () {
    $notifiable = new AnonymousNotifiable;
    $notifiable->route('vonage', '+41791234567');

    $notification = new class extends Notification {};
    $notification->id = 'test-notification-id';

    $event = new NotificationSent($notifiable, $notification, 'vonage');

    $this->listener->handle($event);

    $log = MessageLog::query()->first();
    expect($log)->not->toBeNull()
        ->and($log->channel)->toBe('vonage')
        ->and($log->type)->toStartWith('Illuminate\Notifications\Notification@anonymous')
        ->and($log->notification_id)->toBe('test-notification-id')
        ->and($log->subject)->toBeNull()
        ->and($log->cc)->toBeNull()
        ->and($log->bcc)->toBeNull()
        ->and($log->has_attachment)->toBeFalse();
});

it('skips NovaChannel notifications', function () {
    $notifiable = new AnonymousNotifiable;
    $notification = new class extends Notification {};
    $notification->id = 'nova-id';

    $event = new NotificationSent($notifiable, $notification, 'Laravel\\Nova\\Notifications\\NovaChannel');

    $this->listener->handle($event);

    expect(MessageLog::query()->count())->toBe(0);
});
