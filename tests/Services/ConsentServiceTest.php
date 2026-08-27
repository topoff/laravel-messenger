<?php

use Illuminate\Support\Facades\Cache;
use Topoff\Messenger\Models\MessageType;
use Topoff\Messenger\Services\ConsentService;
use Topoff\Messenger\Services\MessageService;
use Workbench\App\Mail\TestMail;
use Workbench\App\Models\TestReceiver;

it('blocks marketing creation for opted-out receivers, transactional always goes through', function () {
    $marketing = createMessageType(['message_class' => MessageType::CLASS_MARKETING]);
    $receiver = createReceiver();

    app(ConsentService::class)->optOut(TestReceiver::class, $receiver->id);

    (new MessageService)->setReceiver(TestReceiver::class, $receiver->id)
        ->setMessageTypeClass(TestMail::class)
        ->create();

    expect(config('messenger.models.message')::where('message_type_id', $marketing->id)->count())->toBe(0);

    // Same receiver, same opt-out — a TRANSACTIONAL type still goes through.
    $marketing->update(['message_class' => MessageType::CLASS_TRANSACTIONAL]);
    Cache::flush();

    (new MessageService)->setReceiver(TestReceiver::class, $receiver->id)
        ->setMessageTypeClass(TestMail::class)
        ->create();

    expect(config('messenger.models.message')::where('message_type_id', $marketing->id)->count())->toBe(1);
});

it('scopes opt-outs to channel and type and supports opt-in again', function () {
    $marketing = createMessageType(['message_class' => MessageType::CLASS_MARKETING]);
    $receiver = createReceiver();
    $consent = app(ConsentService::class);

    // Type-scoped opt-out blocks exactly this type.
    $consent->optOut(TestReceiver::class, $receiver->id, 'type:'.$marketing->notification_class);
    expect($consent->isOptedOut(TestReceiver::class, $receiver->id, $marketing))->toBeTrue();

    // Opt-in removes the block.
    $consent->optIn(TestReceiver::class, $receiver->id, 'type:'.$marketing->notification_class);
    expect($consent->isOptedOut(TestReceiver::class, $receiver->id, $marketing))->toBeFalse();

    // Channel-scoped opt-out.
    $consent->optOut(TestReceiver::class, $receiver->id, 'channel:'.$marketing->channel);
    expect($consent->isOptedOut(TestReceiver::class, $receiver->id, $marketing))->toBeTrue();
});

it('deletes an already created marketing message at send time after a late opt-out', function () {
    $marketing = createMessageType(['message_class' => MessageType::CLASS_MARKETING]);
    $receiver = createReceiver();
    $message = createMessage(['message_type_id' => $marketing->id, 'receiver_type' => TestReceiver::class, 'receiver_id' => $receiver->id]);

    app(ConsentService::class)->optOut(TestReceiver::class, $receiver->id);

    $handlerClass = $marketing->single_handler;
    new $handlerClass($message)->send();

    expect(config('messenger.models.message')::find($message->id))->toBeNull();
});
