<?php

use Illuminate\Support\Facades\Mail;
use Topoff\Messenger\Jobs\SendMessageJob;
use Topoff\Messenger\Mail\BulkMail;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Services\MessageService;
use Workbench\App\Mail\TestMail;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;
use Workbench\App\Models\TestUuidReceiver;

it('creates a message with UUID receiver and messagable', function () {
    createMessageType();
    $receiver = createUuidReceiver();
    $messagable = createUuidReceiver(['email' => 'uuid-messagable@example.com']);

    (new MessageService)
        ->setReceiver(TestUuidReceiver::class, $receiver->id)
        ->setMessagable(TestUuidReceiver::class, $messagable->id)
        ->setMessageTypeClass(TestMail::class)
        ->create();

    $message = Message::first();

    expect($receiver->id)->toBeString()
        ->and($message->receiver_id)->toBe($receiver->id)
        ->and($message->receiver_type)->toBe(TestUuidReceiver::class)
        ->and($message->messagable_id)->toBe($messagable->id)
        ->and($message->receiver)->not->toBeNull()
        ->and($message->receiver->is($receiver))->toBeTrue();
});

it('sends a UUID-receiver message and sets sent_at', function () {
    Mail::fake();
    config()->set('messenger.sending.check_should_send', fn () => true);

    $messageType = createMessageType(['direct' => true]);
    $receiver = createUuidReceiver();

    $message = createMessage([
        'receiver_type' => TestUuidReceiver::class,
        'receiver_id' => $receiver->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => createMessagable()->id,
    ]);

    (new SendMessageJob)->handle();

    expect($message->fresh()->sent_at)->not->toBeNull();
    Mail::assertSent(TestMail::class);
});

it('groups multiple UUID-receiver messages into a bulk send', function () {
    Mail::fake();
    config()->set('messenger.sending.check_should_send', fn () => true);

    $messageType = createMessageType(['direct' => false]);
    $receiver = createUuidReceiver();

    createMessage([
        'receiver_type' => TestUuidReceiver::class,
        'receiver_id' => $receiver->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => createMessagable()->id,
    ]);

    createMessage([
        'receiver_type' => TestUuidReceiver::class,
        'receiver_id' => $receiver->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => createMessagable(['title' => 'Second'])->id,
    ]);

    (new SendMessageJob)->handle();

    Mail::assertSent(BulkMail::class);
});

it('handles bigint and UUID messages coexisting in the same table', function () {
    Mail::fake();
    config()->set('messenger.sending.check_should_send', fn () => true);

    $messageType = createMessageType(['direct' => true]);

    $bigintReceiver = createReceiver();
    $uuidReceiver = createUuidReceiver();

    $bigintMessage = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $bigintReceiver->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => createMessagable()->id,
    ]);

    $uuidMessage = createMessage([
        'receiver_type' => TestUuidReceiver::class,
        'receiver_id' => $uuidReceiver->id,
        'message_type_id' => $messageType->id,
        'messagable_type' => TestMessagable::class,
        'messagable_id' => createMessagable(['title' => 'For UUID'])->id,
    ]);

    expect($bigintMessage->fresh()->receiver_id)->toBe($bigintReceiver->id)
        ->and($bigintReceiver->id)->toBeInt()
        ->and($uuidMessage->fresh()->receiver_id)->toBe($uuidReceiver->id)
        ->and($uuidReceiver->id)->toBeString()
        ->and($bigintMessage->fresh()->receiver->is($bigintReceiver))->toBeTrue()
        ->and($uuidMessage->fresh()->receiver->is($uuidReceiver))->toBeTrue();

    (new SendMessageJob)->handle();

    expect($bigintMessage->fresh()->sent_at)->not->toBeNull()
        ->and($uuidMessage->fresh()->sent_at)->not->toBeNull();
    Mail::assertSent(TestMail::class, 2);
});
