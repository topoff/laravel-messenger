<?php

namespace Topoff\Messenger\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\VonageMessage;
use Illuminate\Notifications\Notification;

class NovaChannelNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $subject,
        protected string $message,
        protected string $channel,
    ) {}

    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->message);
    }

    public function toVonage(object $notifiable): VonageMessage
    {
        return (new VonageMessage)
            ->content($this->message);
    }
}
