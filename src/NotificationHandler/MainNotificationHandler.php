<?php

namespace Topoff\Messenger\NotificationHandler;

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Topoff\Messenger\Models\Message;

/**
 * Base handler for notification-based channels (SMS, push, etc.).
 *
 * Similar lifecycle to MainMailHandler, but dispatches via
 * $receiver->notify() instead of Mail::to()->send().
 *
 * The receiver only needs the Notifiable trait — MessageReceiverInterface
 * is NOT required.
 */
class MainNotificationHandler
{
    public function __construct(protected Message $message)
    {
        $this->setMessageReserved();
    }

    /**
     * Determine if the message can / should be sent now
     */
    public function shouldBeSentNow(): bool
    {
        return true;
    }

    public function shouldBeSentInThisEnvironment(): bool
    {
        $checker = config('messenger.sending.check_should_send');

        if (is_callable($checker)) {
            return $checker();
        }

        return app()->environment('production');
    }

    /**
     * Determine if the message should be aborted and deleted before sending
     */
    public function abortAndDeleteWhen(): bool
    {
        return false;
    }

    /**
     * Overwrite if you want to do something after sending the notification successfully
     */
    public function onSuccessfulSent(): void {}

    /**
     * Overwrite if you want to do something when the sending failed
     *
     * @return bool whether the error has been handled
     */
    public function onError(Throwable $t): bool
    {
        return false;
    }

    /**
     * Send the notification through the receiver's notify() method
     */
    public function send(): void
    {
        try {
            $this->message->attempts++;
            $receiver = $this->message->receiver;

            if (! $receiver) {
                $this->message->error_message = 'Notification could not be sent because the receiver is missing, presumably trashed.';
                $this->message->error_at = now();
                $this->message->deleted_at = now();
                $this->message->save();

                Log::notice(static::class.':'.__FUNCTION__.': Receiver missing for message_id: '.$this->message->id);

                return;
            }

            if (! $this->shouldBeSentNow()) {
                $this->message->save();

                return;
            }

            if ($this->abortAndDeleteWhen()) {
                $this->message->save();
                $this->message->delete();

                return;
            }

            if (! method_exists($receiver, 'notify')) {
                $this->message->error_message = 'Notification could not be sent because the receiver does not use the Notifiable trait.';
                $this->message->error_at = now();
                $this->message->save();

                Log::error(static::class.':'.__FUNCTION__.': Receiver '.$receiver::class.' does not use Notifiable trait for message_id: '.$this->message->id);

                return;
            }

            $notificationClass = $this->notificationClass();
            $notification = new $notificationClass(...$this->getNotificationParameters());
            $notification->messengerMessageId = $this->message->id;

            if ($this->shouldBeSentInThisEnvironment()) {
                $receiver->notify($notification);
            }

            $this->setMessageSent();
            $this->onSuccessfulSent();
        } catch (Throwable $t) {
            $this->message->reserved_at = null;
            $this->message->error_code = (int) $t->getCode();
            $this->message->error_at = now();
            $this->message->error_message = Str::limit(($t->getCode().' : '.$t->getMessage()), 245);
            $this->message->save();

            $errorHasBeenHandled = $this->onError($t);

            if (! $errorHasBeenHandled) {
                Log::error(static::class.':'.__FUNCTION__.': Notification could not be sent: message_id: '.$this->message->id.' class: '.$this->notificationClass().' - Code: '.$t->getCode().' Message: '
                    .$t->getMessage().' on line '.$t->getLine(), ['trace' => $t->getTrace()]);
            }
        }
    }

    /**
     * Parameters passed to the notification class constructor.
     * Override in child handlers to pass domain-specific arguments.
     */
    public function getNotificationParameters(): array
    {
        return [];
    }

    /**
     * The notification class to instantiate.
     * Defaults to the notification_class defined on the MessageType.
     */
    protected function notificationClass(): string
    {
        return $this->message->messageType->notification_class;
    }

    protected function setMessageReserved(): void
    {
        $this->message->reserved_at = Date::now();
        $this->message->save();
    }

    protected function setMessageSent(): void
    {
        $this->message->sent_at = Date::now();
        $this->message->save();
    }
}
