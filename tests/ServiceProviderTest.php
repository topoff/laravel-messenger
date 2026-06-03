<?php

use Illuminate\Support\Facades\Schema;
use Spatie\LaravelPackageTools\Package;
use Topoff\Messenger\MessengerServiceProvider;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Models\MessageType;
use Topoff\Messenger\Repositories\MessageTypeRepository;

it('registers the package config', function () {
    expect(config('messenger'))->toBeArray()
        ->and(config('messenger.models.message'))->toBe(Message::class)
        ->and(config('messenger.models.message_type'))->toBe(MessageType::class);
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
    expect(Schema::hasTable('message_types'))->toBeTrue()
        ->and(Schema::hasTable('messages'))->toBeTrue()
        ->and(Schema::hasTable('message_log'))->toBeTrue()
        ->and(Schema::hasColumns('message_types', [
            'channel', 'notification_class', 'single_handler', 'bulk_handler',
            'ses_configuration_set', 'max_retry_attempts', 'required_messagable',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('messages', [
            'channel', 'failed_at', 'error_code', 'error_message',
            'tracking_sender_contact', 'tracking_recipient_contact',
        ]))->toBeTrue();
});

it('opts into Spatie runsMigrations so host apps do not need --path on migrate', function () {
    // discoversMigrations() on its own only enables vendor:publish for migrations.
    // Without runsMigrations(), Spatie's discoverPackageMigrations() never calls
    // loadMigrationsFrom() on the file paths, so a plain `php artisan migrate` in a
    // host app does not see them and you have to invoke
    // `migrate --path=vendor/topoff/laravel-messenger/database/migrations`.
    $package = new Package;
    $package->setBasePath(realpath(__DIR__.'/../src'));

    /** @var MessengerServiceProvider $provider */
    $provider = app()->getProvider(MessengerServiceProvider::class);
    $provider->configurePackage($package);

    expect($package->discoversMigrations)->toBeTrue();
    expect($package->runsMigrations)->toBeTrue();
});
