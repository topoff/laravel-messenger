<?php

use Illuminate\Support\Facades\Cache;
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

it('caches scalar attribute arrays, never eloquent objects', function () {
    // Serialized models come back as __PHP_Incomplete_Class from a shared
    // (e.g. database) cache store read by another process.
    createMessageType(['notification_class' => 'App\\Mail\\Scalar']);

    $result = $this->repository->getFromTypeAndCustomer('App\\Mail\\Scalar');

    $key = 'messenger:message-types:f2:v1:'.MessageTypeRepository::class.':getFromTypeAndCustomer:App\\Mail\\Scalar';
    $cached = Cache::tags(config('messenger.cache.tag'))->get($key);

    expect($cached)->toBeArray()
        ->and($cached['notification_class'])->toBe('App\\Mail\\Scalar')
        ->and($result)->toBeInstanceOf(MessageType::class)
        ->and($result->exists)->toBeTrue();
});

it('hydrates a working model from the cached attributes', function () {
    $messageType = createMessageType(['notification_class' => 'App\\Mail\\Rehydrated']);

    $this->repository->getFromTypeAndCustomer('App\\Mail\\Rehydrated'); // warm cache
    MessageType::where('notification_class', 'App\\Mail\\Rehydrated')->forceDelete();

    $fromCache = $this->repository->getFromTypeAndCustomer('App\\Mail\\Rehydrated');

    expect($fromCache->id)->toBe($messageType->id)
        ->and($fromCache->exists)->toBeTrue()
        ->and($fromCache->notification_class)->toBe('App\\Mail\\Rehydrated');
});

it('is registered as singleton', function () {
    $instance1 = app(MessageTypeRepository::class);
    $instance2 = app(MessageTypeRepository::class);

    expect($instance1)->toBe($instance2);
});
