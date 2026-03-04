<?php

namespace Workbench\App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Topoff\Messenger\Models\Message;

class TestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Message $messageModel) {}

    public function build(): static
    {
        return $this->subject('Test Mail')->html('<p>Test</p>');
    }
}
