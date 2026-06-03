<?php

declare(strict_types=1);

namespace Topoff\Messenger\Exceptions;

use RuntimeException;

final class ImapPackageMissingException extends RuntimeException
{
    public static function forClient(): self
    {
        return new self(
            'IMAP support requires webklex/laravel-imap. Install it with: composer require webklex/laravel-imap'
        );
    }
}
