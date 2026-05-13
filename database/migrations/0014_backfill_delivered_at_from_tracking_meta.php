<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot backfill for `messages.delivered_at`.
 *
 * Migration 0013 introduced the `delivered_at` column, but messages whose
 * SES Delivery SNS event was processed by the previous package version
 * (before 0013 ran) only have the delivery info in `tracking_meta.sns_message_delivery`
 * and never had the column populated. This caused the bounce-threshold guard in
 * `UserEmailBouncedListener` to mistake those rows for true permanent bounces.
 *
 * This migration is idempotent: it only touches rows where `delivered_at` is NULL
 * AND `tracking_meta.sns_message_delivery.delivery.timestamp` is present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        if (! Schema::hasColumn('messages', 'delivered_at')) {
            return;
        }

        $jsonPath = '$.sns_message_delivery.delivery.timestamp';

        DB::statement(<<<SQL
            UPDATE messages
            SET delivered_at = STR_TO_DATE(
                SUBSTRING(JSON_UNQUOTE(JSON_EXTRACT(tracking_meta, '{$jsonPath}')), 1, 19),
                '%Y-%m-%dT%H:%i:%s'
            )
            WHERE delivered_at IS NULL
              AND JSON_UNQUOTE(JSON_EXTRACT(tracking_meta, '{$jsonPath}')) IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        // Backfill is non-destructive (only fills NULLs from JSON that is still present).
        // Reverting would require knowing which rows were touched, which we don't track.
    }
};
