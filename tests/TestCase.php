<?php

namespace Topoff\Messenger\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Topoff\Messenger\MessengerServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            MessengerServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('messenger.database.connection');
        config()->set('messenger.logs.connection');
        config()->set('queue.default', 'sync');
        config()->set('cache.default', 'array');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUpDatabase(): void
    {
        // Package tables (message_types, messages, message_log) are created
        // by real migrations loaded via the ServiceProvider.
        // Only test-specific fixture tables are created manually here.
        Schema::create('test_receivers', function (Blueprint $table): void {
            $table->id();
            $table->string('email');
            $table->string('locale')->default('en');
            $table->timestamp('email_invalid_at')->nullable();
        });

        Schema::create('test_senders', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        Schema::create('test_messagables', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
    }
}
