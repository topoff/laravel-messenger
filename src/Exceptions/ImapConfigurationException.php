<?php

declare(strict_types=1);

namespace Topoff\Messenger\Exceptions;

use RuntimeException;

final class ImapConfigurationException extends RuntimeException
{
    public static function unknownInbox(string $inboxKey): self
    {
        return new self("Unknown IMAP inbox key '{$inboxKey}'. Configure it under messenger.imap.inboxes.");
    }

    public static function missingField(string $inboxKey, string $field): self
    {
        return new self("IMAP inbox '{$inboxKey}' is missing required field '{$field}'.");
    }
}
