<?php

namespace Topoff\Messenger\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Topoff\Messenger\Models\Message;

class CustomMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Message $messageModel) {}

    public function build(): self
    {
        $subject = (string) data_get($this->messageModel->params, 'subject', 'Custom Message');
        $view = (string) config('messenger.mail.custom_message_view', 'messenger::customMessage');

        return $this->subject($subject)
            ->markdown($view, [
                'subjectLine' => $subject,
                'markdownBody' => (string) data_get($this->messageModel->params, 'text', ''),
                'receiver' => $this->messageModel->receiver,
            ]);
    }
}
