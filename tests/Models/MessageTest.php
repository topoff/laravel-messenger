<?php

use Illuminate\Support\Facades\Date;
use Topoff\MailManager\Models\Message;
use Topoff\MailManager\Models\MessageType;
use Workbench\App\Models\TestMessagable;
use Workbench\App\Models\TestReceiver;
use Workbench\App\Models\TestSender;

it('can create a message', function () {
    $message = createMessage();

    expect($message)->toBeInstanceOf(Message::class)
        ->and($message->id)->toBeInt();
});

it('uses the configured database connection', function () {
    config()->set('mail-manager.database.connection', 'custom');
    $message = new Message;

    expect($message->getConnectionName())->toBe('custom');
});

it('belongs to a message type', function () {
    $messageType = createMessageType();
    $message = createMessage(['message_type_id' => $messageType->id]);

    expect($message->messageType)->toBeInstanceOf(MessageType::class)
        ->and($message->messageType->id)->toBe($messageType->id);
});

it('has a morphTo receiver relationship', function () {
    $receiver = createReceiver();
    $message = createMessage([
        'receiver_type' => TestReceiver::class,
        'receiver_id' => $receiver->id,
    ]);

    expect($message->receiver)->toBeInstanceOf(TestReceiver::class)
        ->and($message->receiver->id)->toBe($receiver->id);
});

it('has a morphTo sender relationship', function () {
    $sender = createSender();
    $message = createMessage([
        'sender_type' => TestSender::class,
        'sender_id' => $sender->id,
    ]);

    expect($message->sender)->toBeInstanceOf(TestSender::class)
        ->and($message->sender->id)->toBe($sender->id);
});

it('has a morphTo messagable relationship', function () {
    $messagable = createMessagable();
    $message = createMessage([
        'messagable_type' => TestMessagable::class,
        'messagable_id' => $messagable->id,
    ]);

    expect($message->messagable)->toBeInstanceOf(TestMessagable::class)
        ->and($message->messagable->id)->toBe($messagable->id);
});

it('does not crash when messagable_type is an invalid class string', function () {
    $message = createMessage([
        'messagable_type' => 'mail-manager-simulator',
        'messagable_id' => 1,
    ]);

    expect($message->messagable)->toBeNull();
});

it('scopes direct message types', function () {
    $directType = createMessageType(['notification_class' => 'Direct\\Test', 'direct' => true]);
    $indirectType = createMessageType(['notification_class' => 'Indirect\\Test', 'direct' => false]);

    createMessage(['message_type_id' => $directType->id]);
    createMessage(['message_type_id' => $indirectType->id]);

    $directMessages = Message::has('directMessageTypes')->get();

    expect($directMessages)->toHaveCount(1);
});

it('scopes has error and is not sent', function () {
    createMessage(['error_at' => now(), 'sent_at' => null]);
    createMessage(['error_at' => null, 'sent_at' => null]);
    createMessage(['error_at' => now(), 'sent_at' => now()]);

    $errorMessages = Message::hasErrorAndIsNotSent()->get();

    expect($errorMessages)->toHaveCount(1);
});

it('scopes is scheduled but not sent', function () {
    createMessage(['scheduled_at' => now(), 'error_at' => null, 'reserved_at' => null, 'sent_at' => null]);
    createMessage(['scheduled_at' => now(), 'error_at' => now(), 'reserved_at' => null, 'sent_at' => null]);
    createMessage(['scheduled_at' => now(), 'error_at' => null, 'reserved_at' => now(), 'sent_at' => null]);
    createMessage(['scheduled_at' => null, 'error_at' => null, 'reserved_at' => null, 'sent_at' => null]);

    $scheduledMessages = Message::isScheduledButNotSent()->get();

    expect($scheduledMessages)->toHaveCount(1);
});

it('formats the date attribute', function () {
    Date::setTestNow('2025-03-15 10:00:00');

    $message = createMessage();
    // Force created_at since timestamps are off
    $message->update(['created_at' => '2025-03-15 10:00:00']);
    $message->refresh();

    expect($message->dateFormated)->toBeString()->not->toBeEmpty();
});

it('casts params to array', function () {
    $message = createMessage(['params' => ['key' => 'value']]);
    $message->refresh();

    expect($message->params)->toBeArray()
        ->and($message->params['key'])->toBe('value');
});

it('supports soft deletes', function () {
    $message = createMessage();
    $id = $message->id;

    $message->delete();

    expect(Message::find($id))->toBeNull()
        ->and(Message::withTrashed()->find($id))->not->toBeNull();
});

it('scopes today using DateScopesTrait', function () {
    Date::setTestNow('2025-06-15 14:00:00');

    $todayMessage = createMessage();
    $todayMessage->update(['created_at' => '2025-06-15 10:00:00']);

    $oldMessage = createMessage();
    $oldMessage->update(['created_at' => '2025-06-10 10:00:00']);

    expect(Message::today()->count())->toBe(1);
});

it('scopes this month using DateScopesTrait', function () {
    Date::setTestNow('2025-06-15 14:00:00');

    $thisMonthMessage = createMessage();
    $thisMonthMessage->update(['created_at' => '2025-06-05 10:00:00']);

    $lastMonthMessage = createMessage();
    $lastMonthMessage->update(['created_at' => '2025-05-10 10:00:00']);

    expect(Message::thisMonth()->count())->toBe(1);
});
