<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency log for IMAP-based bounce / complaint / reply processing.
 *
 * Each row records that we've already classified one inbound IMAP message,
 * keyed by (inbox_key, fingerprint). The fingerprint is a sha256 of the raw
 * RFC 822 prefix (first ~2 KB) so re-fetching the same message — for any
 * reason — is a no-op even if IMAP flag updates or folder moves fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messenger_imap_processed')) {
            return;
        }

        Schema::create('messenger_imap_processed', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('inbox_key', 64)->index();
            $table->string('fingerprint', 64);
            $table->string('imap_uid', 64)->nullable();
            $table->string('classification', 32);
            $table->json('matched_message_ids')->nullable();
            $table->timestamp('processed_at')->useCurrent();

            $table->unique(['inbox_key', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messenger_imap_processed');
    }
};
