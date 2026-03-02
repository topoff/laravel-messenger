<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('mail-manager.database.connection');
    }

    public function up(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('messages')) {
            return;
        }

        if (! Schema::connection($connection)->hasColumn('messages', 'locale')) {
            Schema::connection($connection)->table('messages', function (Blueprint $table): void {
                $table->string('locale', 5)->nullable()->index()->after('text');
            });
        }
    }

    public function down(): void
    {
        $connection = $this->getConnection();

        if (! Schema::connection($connection)->hasTable('messages')) {
            return;
        }

        if (Schema::connection($connection)->hasColumn('messages', 'locale')) {
            Schema::connection($connection)->table('messages', function (Blueprint $table): void {
                $table->dropColumn('locale');
            });
        }
    }
};
