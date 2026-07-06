<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v9 (E79): status-driven fallback engine. A message type can name a
 * fallback type (usually on another channel) plus a timeout: when a sent
 * message of this type has no delivery confirmation after the timeout —
 * or bounces permanently — the engine creates a follow-up message of the
 * fallback type. `messages.fallback_of_message_id` links the follow-up
 * to its origin (loop protection + reporting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_types', function (Blueprint $table) {
            $table->unsignedBigInteger('fallback_message_type_id')->nullable();
            $table->unsignedInteger('fallback_after_minutes')->nullable();
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('fallback_of_message_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('message_types', function (Blueprint $table) {
            $table->dropColumn(['fallback_message_type_id', 'fallback_after_minutes']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['fallback_of_message_id']);
            $table->dropColumn('fallback_of_message_id');
        });
    }
};
