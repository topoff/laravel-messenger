<?php

namespace Topoff\MailManager\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Topoff\MailManager\MailManagerServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            MailManagerServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('mail-manager.database.connection');
        config()->set('queue.default', 'sync');
        config()->set('cache.default', 'array');
        config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    protected function setUpDatabase(): void
    {
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

        if (! Schema::hasTable('message_types')) {
            Schema::create('message_types', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('channel', 10)->default('email')->index();
                $table->string('notification_class', 80)->index();
                $table->string('single_handler', 120)->nullable();
                $table->string('bulk_handler', 120)->nullable();
                $table->boolean('direct')->default(false);
                $table->boolean('dev_bcc')->default(true);
                $table->unsignedMediumInteger('error_stop_send_minutes')->default(60 * 24 * 3);
                $table->unsignedTinyInteger('max_retry_attempts')->default(10);
                $table->boolean('required_sender')->default(false)->index();
                $table->boolean('required_messagable')->default(false)->index();
                $table->boolean('required_company_id')->default(false)->index();
                $table->boolean('required_scheduled')->default(false)->index();
                $table->boolean('required_text')->default(false)->index();
                $table->boolean('required_params')->default(false)->index();
                $table->string('bulk_message_line')->nullable();
                $table->string('ses_configuration_set')->nullable();
                $table->softDeletes();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
            });
        }

        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('receiver_type', 50)->nullable();
                $table->unsignedBigInteger('receiver_id')->nullable();
                $table->index(['receiver_type', 'receiver_id']);
                $table->string('sender_type', 50)->nullable();
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->index(['sender_type', 'sender_id']);
                $table->unsignedBigInteger('company_id')->index()->nullable();
                $table->unsignedBigInteger('message_type_id')->index();
                $table->string('messagable_type', 50)->nullable();
                $table->unsignedBigInteger('messagable_id')->nullable();
                $table->json('params')->nullable();
                $table->string('locale', 5)->nullable()->index();
                $table->dateTime('scheduled_at')->nullable();
                $table->dateTime('reserved_at')->nullable()->index();
                $table->dateTime('error_at')->nullable()->index();
                $table->dateTime('sent_at')->nullable();
                $table->dateTime('failed_at')->nullable();
                $table->unsignedTinyInteger('attempts')->nullable()->default(0);
                $table->string('channel', 10)->default('email')->index();
                $table->unsignedSmallInteger('error_code')->nullable();
                $table->string('error_message')->nullable();
                $table->string('tracking_hash', 64)->nullable()->index();
                $table->string('tracking_message_id')->nullable()->index();
                $table->string('tracking_sender_name')->nullable();
                $table->string('tracking_sender_contact')->nullable();
                $table->string('tracking_recipient_name')->nullable();
                $table->string('tracking_recipient_contact')->nullable();
                $table->string('tracking_subject')->nullable();
                $table->unsignedInteger('tracking_opens')->default(0);
                $table->unsignedInteger('tracking_clicks')->default(0);
                $table->dateTime('tracking_opened_at')->nullable();
                $table->dateTime('tracking_clicked_at')->nullable();
                $table->json('tracking_meta')->nullable();
                $table->text('tracking_content')->nullable();
                $table->string('tracking_content_path')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->nullable();
                $table->softDeletes();

                $table->foreign('message_type_id')->references('id')->on('message_types');
            });
        }
    }
}
