<?php

use Topoff\Messenger\Repositories\MessageTypeRepository;

it('registers the package config', function () {
    expect(config('messenger'))->toBeArray()
        ->and(config('messenger.models.message'))->toBe(\Topoff\Messenger\Models\Message::class)
        ->and(config('messenger.models.message_type'))->toBe(\Topoff\Messenger\Models\MessageType::class);
});

it('registers MessageTypeRepository as singleton', function () {
    $instance1 = app(MessageTypeRepository::class);
    $instance2 = app(MessageTypeRepository::class);

    expect($instance1)->toBeInstanceOf(MessageTypeRepository::class)
        ->and($instance1)->toBe($instance2);
});

it('registers package views', function () {
    $viewFactory = app('view');
    expect($viewFactory->exists('messenger::bulkMail'))->toBeTrue();
});

it('runs the migration and creates tables', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('message_types'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('messages'))->toBeTrue();
});
