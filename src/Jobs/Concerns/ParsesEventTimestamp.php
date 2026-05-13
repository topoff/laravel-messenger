<?php

namespace Topoff\Messenger\Jobs\Concerns;

use Illuminate\Support\Carbon;
use Throwable;

trait ParsesEventTimestamp
{
    /**
     * Parse a timestamp string from an external event payload (SES SNS, Vonage DLR, …).
     * Falls back to `now()` when the value is missing, not a string, or unparsable —
     * the caller can always rely on getting a usable Carbon instance.
     */
    protected function parseEventTimestamp(mixed $timestamp): Carbon
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return Carbon::now();
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable) {
            return Carbon::now();
        }
    }
}
