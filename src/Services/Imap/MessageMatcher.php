<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Topoff\Messenger\Models\Message;

/**
 * Maps a BounceReport back to one or more Message rows.
 *
 * Lookup priority (high → low confidence):
 *   1. tracking_correlation_id  (UUID from X-Topoff-Message-Id / our Message-ID stamp)
 *   2. tracking_message_id      (SES message ID, when reachable from DSN)
 *   3. tracking_recipient_contact + recent send window (fallback)
 *
 * Returns an Eloquent Collection (possibly empty). Caller decides what to do
 * with empty results — usually log as orphan.
 */
final class MessageMatcher
{
    public function __construct(
        /**
         * Lookback window for the fallback match-by-recipient path. Bounces older than
         * this are not matched by recipient alone, to avoid wrong attribution.
         */
        private readonly int $fallbackLookbackHours = 240,
    ) {}

    /**
     * @return Collection<int, Message>
     */
    public function match(BounceReport $report): Collection
    {
        if ($report->originalCorrelationId !== null && $report->originalCorrelationId !== '') {
            $matches = $this->byCorrelationId($report->originalCorrelationId);
            if ($matches->isNotEmpty()) {
                return $matches;
            }
        }

        if ($report->originalSesMessageId !== null && $report->originalSesMessageId !== '') {
            $matches = $this->bySesMessageId($report->originalSesMessageId);
            if ($matches->isNotEmpty()) {
                return $matches;
            }
        }

        if ($report->recipients !== []) {
            return $this->byRecipientRecent($report->recipients);
        }

        return $this->emptyCollection();
    }

    /**
     * @return Collection<int, Message>
     */
    private function byCorrelationId(string $correlationId): Collection
    {
        return $this->newQuery()
            ->where('tracking_correlation_id', $correlationId)
            ->get();
    }

    /**
     * @return Collection<int, Message>
     */
    private function bySesMessageId(string $sesMessageId): Collection
    {
        return $this->newQuery()
            ->where('tracking_message_id', $sesMessageId)
            ->get();
    }

    /**
     * @param  list<string>  $recipients
     * @return Collection<int, Message>
     */
    private function byRecipientRecent(array $recipients): Collection
    {
        $normalized = array_values(array_unique(array_map(
            fn (string $r): string => mb_strtolower(trim($r)),
            $recipients
        )));

        $since = now()->subHours($this->fallbackLookbackHours);

        return $this->newQuery()
            ->whereIn('tracking_recipient_contact', $normalized)
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();
    }

    /**
     * @return Builder<Message>
     */
    private function newQuery(): Builder
    {
        /** @var class-string<Message> $class */
        $class = config('messenger.models.message');

        return $class::query();
    }

    /**
     * @return Collection<int, Message>
     */
    private function emptyCollection(): Collection
    {
        /** @var class-string<Message> $class */
        $class = config('messenger.models.message');

        return $class::query()->whereRaw('1=0')->get();
    }
}
