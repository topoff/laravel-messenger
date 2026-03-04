<?php

use Topoff\Messenger\Models\MessageType;
use Topoff\Messenger\Repositories\MessageTypeRepository;

beforeEach(function () {
    $this->repository = app(MessageTypeRepository::class);
});

it('gets message type id by mail class', function () {
    $messageType = createMessageType(['notification_class' => 'App\\Mail\\TestMail']);

    $id = $this->repository->getIdFromTypeAndCustomer('App\\Mail\\TestMail');

    expect($id)->toBe($messageType->id);
});

it('gets message type by mail class', function () {
    $messageType = createMessageType(['notification_class' => 'App\\Mail\\FindMe']);

    $result = $this->repository->getFromTypeAndCustomer('App\\Mail\\FindMe');

    expect($result)->toBeInstanceOf(MessageType::class)
        ->and($result->id)->toBe($messageType->id)
        ->and($result->notification_class)->toBe('App\\Mail\\FindMe');
});

it('gets message type by id', function () {
    $messageType = createMessageType();

    $result = $this->repository->getFromId($messageType->id);

    expect($result)->toBeInstanceOf(MessageType::class)
        ->and($result->id)->toBe($messageType->id);
});

it('caches message type lookups', function () {
    $messageType = createMessageType(['notification_class' => 'App\\Mail\\Cached']);

    // First call — hits DB
    $result1 = $this->repository->getFromTypeAndCustomer('App\\Mail\\Cached');

    // Delete from DB
    MessageType::where('notification_class', 'App\\Mail\\Cached')->forceDelete();

    // Second call — should come from cache
    $result2 = $this->repository->getFromTypeAndCustomer('App\\Mail\\Cached');

    expect($result2->id)->toBe($result1->id);
});

it('is registered as singleton', function () {
    $instance1 = app(MessageTypeRepository::class);
    $instance2 = app(MessageTypeRepository::class);

    expect($instance1)->toBe($instance2);
});
