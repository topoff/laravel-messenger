<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'delivered_at')) {
                $table->dateTime('delivered_at')->nullable()->after('failed_at');
            }

            if (! Schema::hasColumn('messages', 'bounced_at')) {
                $table->dateTime('bounced_at')->nullable()->after('delivered_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'bounced_at')) {
                $table->dropColumn('bounced_at');
            }

            if (Schema::hasColumn('messages', 'delivered_at')) {
                $table->dropColumn('delivered_at');
            }
        });
    }
};
