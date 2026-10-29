<?php

declare(strict_types=1);

namespace Topoff\Messenger\Channels;

/**
 * SMS content for the `aws-sms` notification channel. A notification's
 * toAwsSms() may return this or a plain string.
 */
class AwsSmsMessage
{
    public function __construct(public string $content = '') {}

    public function content(string $content): static
    {
        $this->content = $content;

        return $this;
    }
}
