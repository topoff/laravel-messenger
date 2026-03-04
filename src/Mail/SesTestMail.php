<?php

namespace Topoff\Messenger\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class SesTestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function build(): static
    {
        return $this->subject('SES Test Mail')
            ->html('<p>SES test mail.</p>');
    }
}
