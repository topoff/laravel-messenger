<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widens the morph / foreign id columns on `messages` from unsignedBigInteger
 * to string(36) so the package can be used by host applications whose domain
 * models (receiver, sender, messagable, company) use UUID primary keys.
 *
 * Strictly backwards compatible: existing bigint values convert losslessly to
 * their string representation during the ALTER, and all morphTo lookups keep
 * working because the comparison value is bound regardless of storage type.
 *
 * Driver handling:
 *  - SQLite is skipped on purpose: its dynamic typing already stores both
 *    bigint IDs and 36-char UUID strings in an INTEGER-affinity column without
 *    change, and running the ALTER would needlessly rewrite integers as text.
 *  - PostgreSQL needs an explicit `USING <col>::varchar` cast — Laravel's
 *    Blueprint `change()` emits `ALTER COLUMN ... TYPE varchar` without it, and
 *    Postgres refuses to cast bigint→varchar automatically. So we issue the
 *    raw statement ourselves.
 *  - MySQL (and other drivers) use the native Blueprint `change()`, which
 *    performs a lossless in-place MODIFY.
 *
 * Existing indexes — the (receiver_type, receiver_id) and (sender_type,
 * sender_id) composites and the single-column company_id index — are preserved
 * by the column-type change on both MySQL and PostgreSQL (Postgres rebuilds the
 * dependent indexes automatically); none needs to be dropped or recreated.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $columns = ['receiver_id', 'sender_id', 'messagable_id', 'company_id'];

    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            foreach ($this->columns as $column) {
                $connection->statement(
                    sprintf('ALTER TABLE "messages" ALTER COLUMN "%s" TYPE varchar(36) USING "%s"::varchar', $column, $column)
                );
            }

            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            foreach ($this->columns as $column) {
                $table->string($column, 36)->nullable()->change();
            }
        });
    }

    /**
     * No-op: once a host app stores genuine UUIDs in these columns, narrowing
     * back to unsignedBigInteger would be a lossy, failing conversion. The
     * widening is forward-only and intentionally irreversible.
     */
    public function down(): void
    {
        // Intentionally left blank — see the class docblock.
    }
};
