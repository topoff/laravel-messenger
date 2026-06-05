<?php

namespace Topoff\Messenger\Jobs\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

trait RetriesOnMissingTrackedMessage
{
    /**
     * Look up tracked messages by SES message ID, retrying with backoff if not found yet.
     *
     * Returns null when the job was released back to the queue (retry) or when
     * all retry attempts are exhausted — in both cases the caller should return early.
     *
     * @return Collection|null The tracked messages, or null if not found (released or exhausted).
     */
    protected function findTrackedMessagesOrRetry(string $messageId, string $jobName): ?Collection
    {
        $messageClass = config('messenger.models.message');
        $trackedMessages = $messageClass::query()
            ->where('tracking_message_id', $messageId)
            ->get();

        if ($trackedMessages->isNotEmpty()) {
            return $trackedMessages;
        }

        $maxLookupAttempts = 8;

        if ($this->attempts() < $maxLookupAttempts) {
            $delay = min(15 * $this->attempts(), 60);

            Log::warning("{$jobName}: No message found for tracking_message_id, retrying.", [
                'messageId' => $messageId,
                'attempt' => $this->attempts(),
                'retry_delay' => $delay,
            ]);

            $this->release($delay);

            return null;
        }

        // Surface as warning, not error. After 8 retries with backoff (up
        // to ~6.5 min) the tracking_message_id is genuinely orphan, and
        // — per the trait docblock — that is expected noise: SES replays,
        // very old bounces flushed from a long-stalled SNS topic, and
        // external senders sharing the SES identity all land here. The
        // diagnostic context is still emitted so the host app can choose
        // to escalate via its log channel config; we just don't promote
        // every replay to a Sentry Issue (TOP-OFFERTEN-BACKEND-13A).
        Log::warning(
            "{$jobName}: No message found for tracking_message_id after {$this->attempts()} attempts.",
            $this->orphanEventContext($messageId)
        );

        return null;
    }

    /**
     * Build diagnostic context for an orphan SNS event (no matching tracked_message
     * after all retries). Helps distinguish real races from expected replays / old
     * bounces / external senders sharing the SES identity.
     *
     * Consumers of this trait must provide `public array $message` (the SNS payload)
     * and use the `ExtractsSesMessageTags` trait.
     *
     * @return array<string, mixed>
     */
    private function orphanEventContext(string $messageId): array
    {
        $sentAtRaw = data_get($this->message, 'mail.timestamp');
        $ageSeconds = null;
        if (is_string($sentAtRaw) && $sentAtRaw !== '') {
            try {
                $ageSeconds = Carbon::parse($sentAtRaw)->diffInSeconds(Carbon::now(), absolute: true);
            } catch (Throwable) {
                $ageSeconds = null;
            }
        }

        return [
            'messageId' => $messageId,
            'mail_timestamp' => $sentAtRaw,
            'mail_age_seconds' => $ageSeconds,
            'mail_source' => data_get($this->message, 'mail.source'),
            'mail_destination' => data_get($this->message, 'mail.destination'),
            'mail_sending_account_id' => data_get($this->message, 'mail.sendingAccountId'),
            'ses_tags' => $this->extractSesMessageTags($this->message),
        ];
    }
}
