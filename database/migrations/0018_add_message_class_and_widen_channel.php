<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v9 (E79): classify every message type as `transactional` or `marketing`
 * and widen the 10-char `channel` columns for upcoming channels
 * (`whatsapp`, `expo_push`).
 *
 * `message_class` drives the consent guard (marketing requires consent,
 * transactional does not) and the RFC 8058 List-Unsubscribe headers
 * (marketing only). Default `transactional` keeps every existing
 * installation behaving exactly as before — strictly backwards compatible.
 *
 * SQLite skips the channel widening on purpose (dynamic typing ignores
 * varchar lengths — same reasoning as migration 0017).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_types', function (Blueprint $table) {
            $table->string('message_class', 20)->default('transactional')->index();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('message_types', function (Blueprint $table) {
                $table->string('channel', 20)->default('email')->change();
            });

            Schema::table('messages', function (Blueprint $table) {
                $table->string('channel', 20)->default('email')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('message_types', function (Blueprint $table) {
            $table->dropIndex(['message_class']);
            $table->dropColumn('message_class');
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('message_types', function (Blueprint $table) {
                $table->string('channel', 10)->default('email')->change();
            });

            Schema::table('messages', function (Blueprint $table) {
                $table->string('channel', 10)->default('email')->change();
            });
        }
    }
};
