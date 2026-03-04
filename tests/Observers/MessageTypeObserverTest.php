<?php

use Topoff\MailManager\Models\MessageType;
use Topoff\MailManager\Repositories\MessageTypeRepository;

it('clears cache when a message type is created', function () {
    $repository = app(MessageTypeRepository::class);

    // Prime the cache
    $existing = createMessageType(['notification_class' => 'App\\Mail\\Existing']);
    $repository->getFromTypeAndCustomer('App\\Mail\\Existing');

    // Create a new message type — observer should flush cache
    createMessageType(['notification_class' => 'App\\Mail\\New']);

    // Delete the existing record from DB
    MessageType::where('notification_class', 'App\\Mail\\Existing')->forceDelete();

    // Cache was flushed, so this should fail (record no longer in DB)
    expect(fn () => $repository->getFromTypeAndCustomer('App\\Mail\\Existing'))
        ->toThrow(\TypeError::class);
});

it('clears cache when a message type is updated', function () {
    $repository = app(MessageTypeRepository::class);

    $messageType = createMessageType(['notification_class' => 'App\\Mail\\Update']);
    $repository->getFromTypeAndCustomer('App\\Mail\\Update');

    // Update triggers the observer
    $messageType->update(['notification_class' => 'App\\Mail\\Updated']);

    // Old cache key should be gone
    expect(fn () => $repository->getFromTypeAndCustomer('App\\Mail\\Update'))
        ->toThrow(\TypeError::class);
});

it('clears cache when a message type is deleted', function () {
    $repository = app(MessageTypeRepository::class);

    $messageType = createMessageType(['notification_class' => 'App\\Mail\\Delete']);
    $repository->getFromTypeAndCustomer('App\\Mail\\Delete');

    $messageType->delete();

    // Cache was flushed, soft-deleted record won't be found
    expect(fn () => $repository->getFromTypeAndCustomer('App\\Mail\\Delete'))
        ->toThrow(\TypeError::class);
});
