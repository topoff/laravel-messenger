<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('messenger.database.connection');
    }

    public function up(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('message_types')) {
            Schema::connection($connection)->create('message_types', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('mail_class', 80)->index();
                $table->string('single_mail_handler', 120)->nullable();
                $table->string('bulk_mail_handler', 120)->nullable();
                $table->boolean('direct')->default(false);
                $table->boolean('dev_bcc')->default(true);
                $table->unsignedMediumInteger('error_stop_send_minutes')->default(60 * 24 * 3);
                $table->boolean('required_sender')->default(false)->index();
                $table->boolean('required_messagable')->default(false)->index();
                $table->boolean('required_company_id')->default(false)->index();
                $table->boolean('required_scheduled')->default(false)->index();
                $table->boolean('required_mail_text')->default(false)->index();
                $table->boolean('required_params')->default(false)->index();
                $table->string('bulk_message_line')->nullable();
                $table->softDeletes();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->default(DB::raw('NULL ON UPDATE CURRENT_TIMESTAMP'))->nullable();
            });
        }

        if (! Schema::connection($connection)->hasTable('messages')) {
            Schema::connection($connection)->create('messages', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->string('receiver_type', 50)->nullable();
                $table->unsignedBigInteger('receiver_id')->nullable();
                $table->index(['receiver_type', 'receiver_id']);
                $table->string('sender_type', 50)->nullable();
                $table->unsignedBigInteger('sender_id')->nullable();
                $table->index(['sender_type', 'sender_id']);
                $table->unsignedBigInteger('company_id')->index()->nullable();
                $table->unsignedBigInteger('message_type_id')->index();
                $table->string('messagable_type', 50);
                $table->unsignedBigInteger('messagable_id');
                $table->json('params')->nullable();
                $table->text('text')->nullable();
                $table->string('locale', 5)->nullable()->index();
                $table->dateTime('scheduled_at')->nullable();
                $table->dateTime('reserved_at')->nullable()->index();
                $table->dateTime('error_at')->nullable()->index();
                $table->dateTime('sent_at')->nullable();
                $table->unsignedTinyInteger('attempts')->nullable()->default(0);
                $table->unsignedSmallInteger('email_error_code')->nullable();
                $table->string('email_error')->nullable();
                $table->string('tracking_hash', 64)->nullable()->index();
                $table->string('tracking_message_id')->nullable()->index();
                $table->string('tracking_sender_name')->nullable();
                $table->string('tracking_sender_email')->nullable();
                $table->string('tracking_recipient_name')->nullable();
                $table->string('tracking_recipient_email')->nullable();
                $table->string('tracking_subject')->nullable();
                $table->unsignedInteger('tracking_opens')->default(0);
                $table->unsignedInteger('tracking_clicks')->default(0);
                $table->dateTime('tracking_opened_at')->nullable();
                $table->dateTime('tracking_clicked_at')->nullable();
                $table->json('tracking_meta')->nullable();
                $table->text('tracking_content')->nullable();
                $table->string('tracking_content_path')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->default(DB::raw('NULL ON UPDATE CURRENT_TIMESTAMP'))->nullable();
                $table->softDeletes();

                $table->foreign('message_type_id')->references('id')->on('message_types');
            });
        }
    }

    public function down(): void
    {
        $connection = $this->getConnection();

        Schema::connection($connection)->dropIfExists('messages');
        Schema::connection($connection)->dropIfExists('message_types');
    }
};
