<?php

use Topoff\Messenger\Models\MessageType;
use Workbench\App\Mail\TestMail;

it('can create a message type', function () {
    $messageType = createMessageType();

    expect($messageType)->toBeInstanceOf(MessageType::class)
        ->and($messageType->id)->toBeInt()
        ->and($messageType->notification_class)->toBe(TestMail::class)
        ->and($messageType->direct)->toBe(true)
        ->and($messageType->dev_bcc)->toBe(true);
});

it('uses the configured database connection', function () {
    config()->set('messenger.database.connection', 'custom');
    $messageType = new MessageType;

    expect($messageType->getConnectionName())->toBe('custom');
});

it('uses default connection when config is null', function () {
    config()->set('messenger.database.connection');
    $messageType = new MessageType;

    expect($messageType->getConnectionName())->toBeNull();
});

it('casts dev_bcc to boolean', function () {
    $messageType = createMessageType(['dev_bcc' => 1]);

    expect($messageType->dev_bcc)->toBeBool()->toBeTrue();

    $messageType = createMessageType(['dev_bcc' => 0]);

    expect($messageType->dev_bcc)->toBeBool()->toBeFalse();
});

it('scopes direct message types', function () {
    createMessageType(['notification_class' => 'Direct\\Mail', 'direct' => true]);
    createMessageType(['notification_class' => 'Indirect\\Mail', 'direct' => false]);

    $directTypes = MessageType::direct()->get();

    expect($directTypes)->toHaveCount(1)
        ->and($directTypes->first()->notification_class)->toBe('Direct\\Mail');
});

it('supports soft deletes', function () {
    $messageType = createMessageType();
    $id = $messageType->id;

    $messageType->delete();

    expect(MessageType::find($id))->toBeNull()
        ->and(MessageType::withTrashed()->find($id))->not->toBeNull();
});
