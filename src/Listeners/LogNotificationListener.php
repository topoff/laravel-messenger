<?php

namespace Topoff\Messenger\Listeners;

use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Str;
use Topoff\Messenger\Models\NotificationLog;

class LogNotificationListener implements ShouldQueue
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

            $notificationLogModelClass = config('messenger.models.notification_log', NotificationLog::class);
            $notificationLogModelClass::query()->create([
                'channel' => Str::limit((string) $event->channel, 30, ''),
                'notifyable_id' => Str::limit((string) data_get($notifiable, 'id', ''), 48, ''),
                'to' => Str::limit((string) $receiver, 100, ''),
                'type' => Str::limit((string) ($event->notification->type ?? $event->notification::class), 80, ''),
                'notification_id' => Str::limit((string) $event->notification->id, 48, ''),
            ]);
        } catch (Exception) {
            // Intentionally swallow errors: logging should never break notifications.
        }
    }
}
