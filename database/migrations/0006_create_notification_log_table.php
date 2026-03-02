<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('mail-manager.logs.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : config('mail-manager.database.connection');
    }

    public function up(): void
    {
        $connection = $this->getConnection();
        $tableName = (string) config('mail-manager.logs.notification_log_table', 'notification_log');

        if (Schema::connection($connection)->hasTable($tableName)) {
            return;
        }

        Schema::connection($connection)->create($tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('channel', 30);
            $table->string('notifyable_id', 48);
            $table->string('to', 100);
            $table->string('type', 80);
            $table->string('notification_id', 48);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $connection = $this->getConnection();
        $tableName = (string) config('mail-manager.logs.notification_log_table', 'notification_log');

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
