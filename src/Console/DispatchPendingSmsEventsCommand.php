<?php

declare(strict_types=1);

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\Sms\SmsDeliveryRecorder;

/**
 * Safety net for SMS final states whose business event was not dispatched
 * (worker died after the final UPDATE and the delivery job had no attempts
 * left). Fires the pending event once and clears the marker.
 */
class DispatchPendingSmsEventsCommand extends Command
{
    #[\Override]
    protected $signature = 'messenger:dispatch-pending-sms-events
        {--older-than=10 : Only markers older than this many minutes}';

    #[\Override]
    protected $description = 'Dispatch SMS delivery events whose final state was recorded but whose event is still pending.';

    public function handle(SmsDeliveryRecorder $recorder): int
    {
        $messageClass = config('messenger.models.message');
        $olderThan = max(0, (int) $this->option('older-than'));

        $ids = $messageClass::query()
            ->where('tracking_meta', 'like', '%"'.SmsDeliveryRecorder::PENDING_KEY.'"%')
            ->where('updated_at', '<=', now()->subMinutes($olderThan))
            ->limit(500)
            ->pluck('id');

        $dispatched = 0;
        foreach ($ids as $id) {
            try {
                if ($recorder->dispatchPending($id)) {
                    $dispatched++;
                }
            } catch (Throwable $t) {
                report($t);
                $this->warn("Message {$id}: event dispatch failed ({$t->getMessage()}).");
            }
        }

        $this->info("Dispatched pending SMS events for {$dispatched} message(s).");

        return self::SUCCESS;
    }
}
