<?php

namespace Topoff\Messenger\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Topoff\Messenger\Models\MessageLog;

class LogNotificationToMessageLogListener implements ShouldQueue
{
    public function handle(NotificationSent $event): void
    {
        try {
            if ($event->channel === 'Laravel\\Nova\\Notifications\\NovaChannel') {
                return;
            }

            $notifiable = $event->notifiable;
            $receiver = match ($event->channel) {
                'mail' => data_get($notifiable, 'email'),
                default => data_get($notifiable, 'phone'),
            };

            $messageLogModelClass = config('messenger.models.message_log', MessageLog::class);
            $messageLogModelClass::query()->create([
                'channel' => Str::limit((string) $event->channel, 30, ''),
                'to' => Str::limit((string) $receiver, 100, ''),
                'type' => Str::limit((string) ($event->notification->type ?? $event->notification::class), 80, ''),
                'notifyable_id' => Str::limit((string) data_get($notifiable, 'id', ''), 48, ''),
                'notification_id' => Str::limit((string) $event->notification->id, 48, ''),
            ]);
        } catch (Exception $e) {
            Log::error('LogNotificationToMessageLogListener: Failed to log notification to message_log.', ['error' => $e->getMessage()]);
        }
    }
}
