<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('messenger.logs.connection');

        return is_string($connection) && $connection !== ''
            ? $connection
            : config('messenger.database.connection');
    }

    public function up(): void
    {
        $connection = $this->getConnection();
        $tableName = (string) config('messenger.logs.email_log_table', 'email_log');

        if (Schema::connection($connection)->hasTable($tableName)) {
            return;
        }

        Schema::connection($connection)->create($tableName, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('to', 100);
            $table->string('cc', 100)->nullable();
            $table->string('bcc', 60)->nullable();
            $table->string('subject', 80);
            $table->boolean('has_attachment')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        $connection = $this->getConnection();
        $tableName = (string) config('messenger.logs.email_log_table', 'email_log');

        Schema::connection($connection)->dropIfExists($tableName);
    }
};
