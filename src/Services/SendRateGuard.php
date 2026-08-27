<?php

namespace Topoff\Messenger\Services;

use Illuminate\Support\Carbon;
use Topoff\Messenger\Models\Message;

/**
 * Per-channel send rate guard (v9, E79/E118): defends against SMS
 * pumping / runaway sends. Configured per channel via
 * `messenger.rate_limit.channels.<channel>`:
 *
 *  - min_interval_seconds   between two sends to the same receiver
 *  - per_receiver_per_day   across all messages to one receiver
 *  - per_channel_per_day    global daily cap for the channel
 *
 * A deferred message is NOT an error: it keeps its record, gets a new
 * `scheduled_at` and goes out on a later run. Counting is DB-based
 * (messages.sent_at), so the guard needs no cache and survives restarts.
 */
class SendRateGuard
{
    /**
     * @return bool true = defer this send (scheduled_at has been moved)
     */
    public function defers(Message $message): bool
    {
        $channel = $message->messageType->channel;
        $limits = config('messenger.rate_limit.channels.'.$channel);

        if (! is_array($limits) || $limits === []) {
            return false;
        }

        $messageClass = config('messenger.models.message');
        $channelQuery = fn () => $messageClass::whereNotNull('sent_at')
            ->whereHas('messageType', fn ($query) => $query->where('channel', $channel));

        $globalCap = (int) ($limits['per_channel_per_day'] ?? 0);

        if ($globalCap > 0 && $channelQuery()->where('sent_at', '>=', Carbon::now()->startOfDay())->count() >= $globalCap) {
            return $this->defer($message, Carbon::now()->addHour());
        }

        $receiverCap = (int) ($limits['per_receiver_per_day'] ?? 0);
        $receiverQuery = fn () => $channelQuery()
            ->where('receiver_type', $message->receiver_type)
            ->where('receiver_id', $message->receiver_id);

        if ($receiverCap > 0 && $receiverQuery()->where('sent_at', '>=', Carbon::now()->startOfDay())->count() >= $receiverCap) {
            return $this->defer($message, Carbon::now()->addDay()->startOfDay()->addHours(8));
        }

        $interval = (int) ($limits['min_interval_seconds'] ?? 0);

        if ($interval > 0) {
            $lastSent = $receiverQuery()->max('sent_at');

            if ($lastSent !== null && Carbon::parse($lastSent)->addSeconds($interval)->isFuture()) {
                return $this->defer($message, Carbon::parse($lastSent)->addSeconds($interval));
            }
        }

        return false;
    }

    protected function defer(Message $message, Carbon $until): bool
    {
        $message->scheduled_at = $until;
        $message->reserved_at = null;

        return true;
    }
}
