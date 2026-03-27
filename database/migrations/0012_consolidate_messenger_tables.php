<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated migration for all messenger tables.
 *
 * This replaces migrations 0001–0011 for fresh installations.
 * For existing installations where tables already exist, this migration is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_types')) {
            Schema::create('message_types', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('channel', 10)->default('email')->index();
                $table->string('notification_class', 80)->index();
                $table->string('single_handler', 120)->nullable();
                $table->string('bulk_handler', 120)->nullable();
                $table->string('ses_configuration_set', 80)->nullable();
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
                $table->string('channel', 10)->default('email')->index();
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

        $logConnection = config('messenger.logs.connection') ?: config('database.default');
        $logTable = config('messenger.logs.message_log_table', 'message_log');

        if (! Schema::connection($logConnection)->hasTable($logTable)) {
            Schema::connection($logConnection)->create($logTable, function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('channel', 30);
                $table->string('to', 100);
                $table->string('type', 80)->nullable();
                $table->string('subject', 80)->nullable();
                $table->string('cc', 100)->nullable();
                $table->string('bcc', 60)->nullable();
                $table->boolean('has_attachment')->default(false);
                $table->string('notifyable_id', 48)->nullable();
                $table->string('notification_id', 48)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('channel');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_types');

        $logConnection = config('messenger.logs.connection') ?: config('database.default');
        $logTable = config('messenger.logs.message_log_table', 'message_log');
        Schema::connection($logConnection)->dropIfExists($logTable);
    }
};
