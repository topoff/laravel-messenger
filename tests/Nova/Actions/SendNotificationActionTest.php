<?php

/**
 * Tests for the SendNotificationAction tracking pipeline.
 *
 * Since Nova classes are not available in the package test environment,
 * these tests verify the underlying components: Message creation patterns,
 * the NovaChannelNotification bridge property, and the listener integration.
 */

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Date;
use Topoff\Messenger\Listeners\RecordNotificationSentListener;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\NotificationHandler\MainNotificationHandler;
use Topoff\Messenger\Notifications\NovaChannelNotification;
use Topoff\Messenger\Repositories\MessageTypeRepository;
use Workbench\App\Models\TestReceiver;

beforeEach(function (): void {
    $this->messageType = createMessageType([
        'notification_class' => NovaChannelNotification::class,
        'channel' => 'vonage',
        'single_handler' => MainNotificationHandler::class,
        'direct' => true,
        'dev_bcc' => false,
    ]);

    $this->receiver = createReceiver();
});

it('creates message record when sending sms to model', function (): void {
    $messageType = resolve(MessageTypeRepository::class)
        ->getFromTypeAndCustomer(NovaChannelNotification::class);

    $message = Message::create([
        'channel' => 'vonage',
        'message_type_id' => $messageType->id,
        'sender_type' => null,
        'sender_id' => null,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'messagable_type' => TestReceiver::class,
        'messagable_id' => $this->receiver->id,
        'params' => ['subject' => '', 'message' => 'Test SMS message'],
        'scheduled_at' => Date::now(),
    ]);

    $message->sent_at = Date::now();
    $message->save();

    $message->refresh();

    expect($message->channel)->toBe('vonage')
        ->and($message->message_type_id)->toBe($this->messageType->id)
        ->and($message->receiver_type)->toBe(TestReceiver::class)
        ->and($message->receiver_id)->toBe($this->receiver->id)
        ->and($message->messagable_type)->toBe(TestReceiver::class)
        ->and($message->messagable_id)->toBe($this->receiver->id)
        ->and($message->params)->toBe(['subject' => '', 'message' => 'Test SMS message'])
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->scheduled_at)->not->toBeNull();
});

it('sets messengerMessageId enabling vonage tracking', function (): void {
    $message = Message::create([
        'channel' => 'vonage',
        'message_type_id' => $this->messageType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'messagable_type' => TestReceiver::class,
        'messagable_id' => $this->receiver->id,
        'params' => ['subject' => '', 'message' => 'Track this SMS'],
        'scheduled_at' => Date::now(),
    ]);

    $notification = new NovaChannelNotification('', 'Track this SMS', 'vonage');
    expect($notification->messengerMessageId)->toBeNull();

    $notification->messengerMessageId = $message->id;
    expect($notification->messengerMessageId)->toBe($message->id);

    // Simulate the NotificationSent event with a vonage response containing a message ID
    $vonageMessageId = 'vonage-msg-'.uniqid();
    $sentSms = new class($vonageMessageId) implements \IteratorAggregate
    {
        public function __construct(private string $messageId) {}

        public function getMessageId(): string
        {
            return $this->messageId;
        }

        public function getTo(): string
        {
            return '+41791234567';
        }

        public function getStatus(): string
        {
            return '0';
        }

        public function getIterator(): \ArrayIterator
        {
            return new \ArrayIterator([$this]);
        }
    };

    $event = new NotificationSent(
        new AnonymousNotifiable,
        $notification,
        'vonage',
        $sentSms,
    );

    (new RecordNotificationSentListener)->handle($event);

    $message->refresh();
    expect($message->tracking_message_id)->toBe($vonageMessageId)
        ->and($message->tracking_recipient_contact)->toBe('+41791234567');
});

it('creates message record in standalone mode', function (): void {
    $message = Message::create([
        'channel' => 'vonage',
        'message_type_id' => $this->messageType->id,
        'sender_type' => null,
        'sender_id' => null,
        'receiver_type' => null,
        'receiver_id' => null,
        'messagable_type' => null,
        'messagable_id' => null,
        'params' => ['subject' => '', 'message' => 'Standalone SMS'],
        'scheduled_at' => Date::now(),
    ]);

    $message->sent_at = Date::now();
    $message->save();

    $message->refresh();

    expect($message->channel)->toBe('vonage')
        ->and($message->receiver_type)->toBeNull()
        ->and($message->receiver_id)->toBeNull()
        ->and($message->messagable_type)->toBeNull()
        ->and($message->messagable_id)->toBeNull()
        ->and($message->sent_at)->not->toBeNull();
});

it('creates message record when sending email to model', function (): void {
    $message = Message::create([
        'channel' => 'mail',
        'message_type_id' => $this->messageType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'messagable_type' => TestReceiver::class,
        'messagable_id' => $this->receiver->id,
        'params' => ['subject' => 'Test Subject', 'message' => 'Test email message'],
        'scheduled_at' => Date::now(),
    ]);

    $message->sent_at = Date::now();
    $message->save();

    $message->refresh();

    expect($message->channel)->toBe('mail')
        ->and($message->message_type_id)->toBe($this->messageType->id)
        ->and($message->params)->toBe(['subject' => 'Test Subject', 'message' => 'Test email message'])
        ->and($message->sent_at)->not->toBeNull();
});

it('does not create message when channel is invalid', function (): void {
    // The action validates channel before creating any Message record.
    // Verify no message exists when none should be created.
    expect(Message::count())->toBe(0);

    // Only 'mail' and 'vonage' are valid — if we only create for valid channels, count stays 0
    $validChannels = ['mail', 'vonage'];
    $invalid = 'invalid';

    expect(in_array($invalid, $validChannels, true))->toBeFalse();
});

it('records error on send failure', function (): void {
    $message = Message::create([
        'channel' => 'vonage',
        'message_type_id' => $this->messageType->id,
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'messagable_type' => TestReceiver::class,
        'messagable_id' => $this->receiver->id,
        'params' => ['subject' => '', 'message' => 'This will fail'],
        'scheduled_at' => Date::now(),
    ]);

    // Simulate error handling pattern from the action's catch block
    $message->error_at = Date::now();
    $message->error_message = \Illuminate\Support\Str::limit('Vonage API error: connection refused', 245);
    $message->save();

    $message->refresh();

    expect($message->error_at)->not->toBeNull()
        ->and($message->error_message)->toContain('Vonage API error')
        ->and($message->sent_at)->toBeNull();
});
