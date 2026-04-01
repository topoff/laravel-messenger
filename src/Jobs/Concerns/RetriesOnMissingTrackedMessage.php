<?php

namespace Topoff\Messenger\Jobs\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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

        Log::error("{$jobName}: No message found for tracking_message_id after {$this->attempts()} attempts.", [
            'messageId' => $messageId,
        ]);

        return null;
    }
}
