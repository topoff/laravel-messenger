<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v9 (E79): preference / consent storage for the central guard in
 * MessageService. One row = one opt-out of a receiver for a scope:
 *
 *  - 'marketing'                → all marketing-class message types
 *  - 'channel:<channel>'        → all marketing types on one channel
 *  - 'type:<notification_class>' → one specific marketing type
 *
 * Transactional message types NEVER consult this table — password resets
 * and the like must always go through.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->string('receiver_type');
            $table->string('receiver_id', 36);
            $table->string('scope', 190)->default('marketing');
            $table->timestamps();

            $table->unique(['receiver_type', 'receiver_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_opt_outs');
    }
};
