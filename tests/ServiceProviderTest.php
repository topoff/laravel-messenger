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

it('runs package migrations and creates tables with expected columns', function () {
    expect(\Illuminate\Support\Facades\Schema::hasTable('message_types'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('messages'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasTable('message_log'))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasColumns('message_types', [
            'channel', 'notification_class', 'single_handler', 'bulk_handler',
            'ses_configuration_set', 'max_retry_attempts', 'required_messagable',
        ]))->toBeTrue()
        ->and(\Illuminate\Support\Facades\Schema::hasColumns('messages', [
            'channel', 'failed_at', 'error_code', 'error_message',
            'tracking_sender_contact', 'tracking_recipient_contact',
        ]))->toBeTrue();
});
