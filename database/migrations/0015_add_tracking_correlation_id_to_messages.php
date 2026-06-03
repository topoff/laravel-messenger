<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `tracking_correlation_id` to the messages table.
 *
 * This UUID is stamped into outgoing emails as the RFC 5322 Message-ID and as
 * the X-Topoff-Message-Id header. When a bounce/complaint arrives via IMAP
 * (not SNS), the DSN's "returned message" part still carries our original
 * headers, so we can look up the originating Message by correlation_id
 * regardless of whether SES ever issued a tracking_message_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'tracking_correlation_id')) {
                $table->uuid('tracking_correlation_id')->nullable()->after('tracking_message_id');
                $table->index('tracking_correlation_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'tracking_correlation_id')) {
                $table->dropIndex(['tracking_correlation_id']);
                $table->dropColumn('tracking_correlation_id');
            }
        });
    }
};
