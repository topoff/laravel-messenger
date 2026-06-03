<?php

/**
 * Default getNotificationParameters() must reconstruct a notification's
 * constructor arguments from the Message's persisted params + channel
 * column, so a queued retry (SendMessageJob::retryDirectMessages) can
 * instantiate the notification class without a custom subclass override.
 */

use Illuminate\Notifications\Notification;
use Topoff\Messenger\NotificationHandler\MainNotificationHandler;
use Topoff\Messenger\Notifications\NovaChannelNotification;

beforeEach(function (): void {
    $this->messageType = createMessageType([
        'notification_class' => NovaChannelNotification::class,
        'single_handler' => MainNotificationHandler::class,
    ]);
});

it('reconstructs NovaChannelNotification constructor params from message params + channel column', function (): void {
    $message = createMessage([
        'message_type_id' => $this->messageType->id,
        'channel' => 'mail',
        'params' => [
            'subject' => 'Welcome',
            'message' => 'Hello world',
        ],
    ]);

    $handler = new MainNotificationHandler($message);

    expect($handler->getNotificationParameters())->toEqual([
        'subject' => 'Welcome',
        'message' => 'Hello world',
        'channel' => 'mail',
    ]);

    // Constructor instantiates successfully — this is the regression case.
    /** @var class-string<NovaChannelNotification> $class */
    $class = NovaChannelNotification::class;
    $notification = new $class(...$handler->getNotificationParameters());
    expect($notification)->toBeInstanceOf(NovaChannelNotification::class);
});

it('does not overwrite an explicit channel in params', function (): void {
    $message = createMessage([
        'message_type_id' => $this->messageType->id,
        'channel' => 'mail',
        'params' => [
            'subject' => 'Sub',
            'message' => 'Body',
            'channel' => 'sms',
        ],
    ]);

    $handler = new MainNotificationHandler($message);

    expect($handler->getNotificationParameters()['channel'])->toBe('sms');
});

it('returns empty params unchanged when notification class does not accept channel', function (): void {
    $messageType = createMessageType([
        'notification_class' => NotificationWithoutChannel::class,
        'single_handler' => MainNotificationHandler::class,
    ]);

    $message = createMessage([
        'message_type_id' => $messageType->id,
        'channel' => 'mail',
        'params' => ['greeting' => 'hi'],
    ]);

    $handler = new MainNotificationHandler($message);

    expect($handler->getNotificationParameters())->toEqual(['greeting' => 'hi']);
});

it('returns empty array when message has no params and constructor takes no channel', function (): void {
    $messageType = createMessageType([
        'notification_class' => NotificationWithoutChannel::class,
        'single_handler' => MainNotificationHandler::class,
    ]);

    $message = createMessage([
        'message_type_id' => $messageType->id,
        'channel' => 'mail',
        'params' => null,
    ]);

    $handler = new MainNotificationHandler($message);

    expect($handler->getNotificationParameters())->toEqual([]);
});

/**
 * Fixture: a notification class whose constructor does NOT accept a
 * `channel` parameter, to verify the channel auto-supply is gated.
 */
class NotificationWithoutChannel extends Notification
{
    public function __construct(public string $greeting) {}
}
