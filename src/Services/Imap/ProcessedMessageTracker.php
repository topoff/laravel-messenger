<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DB-backed idempotency log for IMAP processing. Reserves a fingerprint
 * before doing any work; if the unique constraint trips, the caller knows
 * the message was already processed and can skip safely.
 */
class ProcessedMessageTracker
{
    private const TABLE = 'messenger_imap_processed';

    public function fingerprint(string $rawMessage): string
    {
        return hash('sha256', substr($rawMessage, 0, 2048));
    }

    public function isProcessed(string $inboxKey, string $fingerprint): bool
    {
        return $this->connection()
            ->table(self::TABLE)
            ->where('inbox_key', $inboxKey)
            ->where('fingerprint', $fingerprint)
            ->exists();
    }

    /**
     * Reserve the fingerprint atomically. Returns false if another worker
     * already reserved it (unique constraint), true on success.
     */
    public function reserve(string $inboxKey, string $fingerprint, ?string $imapUid, BounceClassification $classification): bool
    {
        try {
            $this->connection()
                ->table(self::TABLE)
                ->insert([
                    'inbox_key' => $inboxKey,
                    'fingerprint' => $fingerprint,
                    'imap_uid' => $imapUid,
                    'classification' => $classification->value,
                    'matched_message_ids' => null,
                    'processed_at' => now(),
                ]);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<int>  $matchedMessageIds
     */
    public function recordMatches(string $inboxKey, string $fingerprint, array $matchedMessageIds): void
    {
        $this->connection()
            ->table(self::TABLE)
            ->where('inbox_key', $inboxKey)
            ->where('fingerprint', $fingerprint)
            ->update(['matched_message_ids' => json_encode($matchedMessageIds)]);
    }

    private function connection(): Connection
    {
        $name = config('messenger.database.connection');

        return is_string($name) && $name !== '' ? DB::connection($name) : DB::connection();
    }
}
