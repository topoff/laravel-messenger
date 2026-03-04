<?php

use Illuminate\Support\Facades\Mail;
use Topoff\Messenger\Exceptions\ReceiverMissingException;
use Topoff\Messenger\MailHandler\MainMailHandler;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;

beforeEach(function () {
    $this->messageType = createMessageType();
    $this->receiver = createReceiver();
    $this->messagable = createMessagable();
});

it('reserves the message on construction', function () {
    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    expect($message->reserved_at)->toBeNull();

    $handler = new MainMailHandler($message->load('messageType'));

    $message->refresh();
    expect($message->reserved_at)->not->toBeNull();
});

it('sends a message successfully', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => true);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    $message->refresh();
    expect($message->sent_at)->not->toBeNull()
        ->and($message->attempts)->toBe(1);

    Mail::assertSent(\Workbench\App\Mail\TestMail::class);
});

it('sets sent_at even when not sending in this environment', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => false);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    $message->refresh();
    expect($message->sent_at)->not->toBeNull();

    Mail::assertNothingSent();
});

it('aborts and deletes when messagable is missing', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => true);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => 99999, // non-existent
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    $message->refresh();
    expect($message->error_message)->toContain('Messagable itself is missing')
        ->and($message->trashed())->toBeTrue();

    Mail::assertNothingSent();
});

it('aborts and deletes when receiver email is invalid', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => true);

    $this->receiver->update(['email_invalid_at' => now()]);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    $message->refresh();
    expect($message->error_message)->toContain('receiver email is invalid')
        ->and($message->trashed())->toBeTrue();

    Mail::assertNothingSent();
});

it('handles ReceiverMissingException when receiver is null', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => true);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => 99999, // non-existent receiver
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    $message->refresh();
    expect($message->error_message)->toContain('receiver is missing')
        ->and($message->error_code)->toBe(ReceiverMissingException::USER_DELETED)
        ->and($message->trashed())->toBeTrue();

    Mail::assertNothingSent();
});

it('increments attempts on each send', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => true);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    $message->refresh();
    expect($message->attempts)->toBe(1);
});

it('uses check_should_send config callback', function () {
    Mail::fake();

    $called = false;
    config()->set('messenger.sending.check_should_send', function () use (&$called) {
        $called = true;

        return true;
    });

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    expect($called)->toBeTrue();
});

it('defaults to production-only sending when check_should_send is null', function () {
    config()->set('messenger.sending.check_should_send');

    $handler = new MainMailHandler(
        createMessage([
            'receiver_type' => TestReceiver::class,
            'receiver_id' => $this->receiver->id,
            'message_type_id' => $this->messageType->id,
            'messagable_type' => TestMessagable::class,
            'messagable_id' => $this->messagable->id,
        ])->load('messageType')
    );

    // In testing environment, should return false
    expect($handler->shouldBeSentInThisEnvironment())->toBeFalse();
});

it('builds data for bulk mail from message type', function () {
    $messageType = createMessageType(['bulk_message_line' => 'Custom bulk line']);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));

    expect($handler->buildDataBulkMail())->toBe('Custom bulk line');
});

it('uses mail class from message type', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => true);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $this->receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    Mail::assertSent(\Workbench\App\Mail\TestMail::class);
});

it('sends mail to the receiver email address', function () {
    Mail::fake();

    config()->set('messenger.sending.check_should_send', fn () => true);

    $receiver = createReceiver(['email' => 'specific@example.com']);

    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
        'message_type_id' => $this->messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $this->messagable->id,
    ]);

    $handler = new MainMailHandler($message->load('messageType'));
    $handler->send();

    Mail::assertSent(\Workbench\App\Mail\TestMail::class, fn ($mail) => $mail->hasTo('specific@example.com'));
});
