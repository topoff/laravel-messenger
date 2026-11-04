<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Sms;

/**
 * The messenger's own SMS client reference (`msg:<message id>`). Vonage echoes
 * it in the delivery receipt of every part of a multipart SMS, so all parts can
 * be matched by primary key. Only references with exactly this prefix count —
 * a host's own client reference is never read as a messenger id.
 */
final class SmsClientReference
{
    public const string PREFIX = 'msg:';

    /** Vonage accepts at most 40 characters. */
    private const int MAX_LENGTH = 40;

    /**
     * The client reference for a messenger message, or '' (no reference) when
     * the notification is not sent through the messenger.
     */
    public static function for(mixed $messengerMessageId): string
    {
        if ((! is_int($messengerMessageId) && ! is_string($messengerMessageId)) || $messengerMessageId === '') {
            return '';
        }

        $reference = self::PREFIX.$messengerMessageId;

        return strlen($reference) <= self::MAX_LENGTH ? $reference : '';
    }

    /**
     * The messenger message id carried by a client reference, or null for any
     * other (or missing) reference.
     */
    public static function messengerMessageId(mixed $clientReference): ?string
    {
        if (! is_string($clientReference) || preg_match('/^msg:([A-Za-z0-9-]{1,36})$/', $clientReference, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
