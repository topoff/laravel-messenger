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
        if (! Schema::connection($this->getConnection())->hasColumn('messages', 'failed_at')) {
            Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
                $table->dateTime('failed_at')->nullable()->after('sent_at');
            });
        }

        if (! Schema::connection($this->getConnection())->hasColumn('message_types', 'max_retry_attempts')) {
            Schema::connection($this->getConnection())->table('message_types', function (Blueprint $table): void {
                $table->unsignedTinyInteger('max_retry_attempts')->default(10)->after('error_stop_send_minutes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->getConnection())->hasColumn('messages', 'failed_at')) {
            Schema::connection($this->getConnection())->table('messages', function (Blueprint $table): void {
                $table->dropColumn('failed_at');
            });
        }

        if (Schema::connection($this->getConnection())->hasColumn('message_types', 'max_retry_attempts')) {
            Schema::connection($this->getConnection())->table('message_types', function (Blueprint $table): void {
                $table->dropColumn('max_retry_attempts');
            });
        }
    }
};
