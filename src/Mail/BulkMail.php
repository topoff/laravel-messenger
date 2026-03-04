<?php

namespace Topoff\Messenger\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Topoff\Messenger\Contracts\MessageReceiverInterface;

class BulkMail extends Mailable
{
    use Queueable, SerializesModels;

    public ?string $url = null;

    public function __construct(protected MessageReceiverInterface $messageReceiver, public Collection $messages) {}

    public function build(): static
    {
        $urlResolver = config('messenger.mail.bulk_mail_url');
        if (is_callable($urlResolver)) {
            $this->url = $urlResolver($this->messageReceiver);
        }

        $subjectResolver = config('messenger.mail.bulk_mail_subject');
        if (is_callable($subjectResolver)) {
            $this->subject($subjectResolver($this->messageReceiver, $this->messages));
        } else {
            $this->subject($this->messages->count().' messages');
        }

        $view = config('messenger.mail.bulk_mail_view', 'messenger::bulkMail');

        return $this->markdown($view);
    }
}
