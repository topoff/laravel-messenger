<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('messenger.database.connection');
    }

    public function up(): void
    {
        Schema::connection($this->getConnection())->table('message_types', function (Blueprint $table): void {
            $table->renameColumn('mail_class', 'notification_class');
            $table->renameColumn('single_mail_handler', 'single_handler');
            $table->renameColumn('bulk_mail_handler', 'bulk_handler');
            $table->renameColumn('required_mail_text', 'required_text');
        });

        Schema::connection($this->getConnection())->table('message_types', function (Blueprint $table): void {
            $table->string('channel', 10)->default('email')->index()->after('id');
        });

        Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
            $table->renameColumn('email_error_code', 'error_code');
            $table->renameColumn('email_error', 'error_message');
            $table->renameColumn('tracking_sender_email', 'tracking_sender_contact');
            $table->renameColumn('tracking_recipient_email', 'tracking_recipient_contact');
        });

        Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
            $table->string('channel', 10)->default('email')->index()->after('message_type_id');
        });

        Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
            $table->dropColumn('text');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
            $table->text('text')->nullable()->after('params');
        });

        Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });

        Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
            $table->renameColumn('error_code', 'email_error_code');
            $table->renameColumn('error_message', 'email_error');
            $table->renameColumn('tracking_sender_contact', 'tracking_sender_email');
            $table->renameColumn('tracking_recipient_contact', 'tracking_recipient_email');
        });

        Schema::connection($this->getConnection())->table('message_types', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });

        Schema::connection($this->getConnection())->table('message_types', function (Blueprint $table): void {
            $table->renameColumn('notification_class', 'mail_class');
            $table->renameColumn('single_handler', 'single_mail_handler');
            $table->renameColumn('bulk_handler', 'bulk_mail_handler');
            $table->renameColumn('required_text', 'required_mail_text');
        });
    }
};
