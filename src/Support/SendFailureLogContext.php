<?php

declare(strict_types=1);

namespace Topoff\Messenger\Support;

use Throwable;
use Topoff\Messenger\Models\Message;

/**
 * Log context for a failed send that carries no personal data.
 *
 * Send failures used to log the mail parameters (the whole Message with its
 * receiver, company, contact and messagable) plus the raw exception trace,
 * whose frame arguments may hold the same models. Those reached Sentry
 * verbatim. This context keeps only identifiers, classes and error codes;
 * e-mail addresses inside the exception message are masked.
 */
final class SendFailureLogContext
{
    private const int MAX_TRACE_FRAMES = 15;

    /**
     * @return array<string, mixed>
     */
    public static function for(Message $message, Throwable $t, ?string $handlerClass = null): array
    {
        return [
            'message_id' => $message->getKey(),
            'message_type_id' => $message->message_type_id,
            'receiver_type' => $message->receiver_type,
            'receiver_id' => $message->receiver_id,
            'messagable_type' => $message->messagable_type,
            'messagable_id' => $message->messagable_id,
            'company_id' => $message->company_id,
            'handler' => $handlerClass,
            'attempts' => $message->attempts,
            ...self::forThrowable($t),
        ];
    }

    /**
     * Masks e-mail addresses (also syntactically broken ones such as
     * "info@example.ch___deleted" or quoted local parts) and phone-number-like
     * digit runs in a free-text error message. Free-text names cannot be
     * detected — callers must never put model data into the message itself.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace('/(?:"(?:[^"\\\\]|\\\\.)*"|[^\s"\'<>(),;:]+)@[^\s"\'<>(),;:]+/u', '[email]', $text);

        // Phone-like runs need at least 7 digits; SMTP status codes such as
        // "554 5.7.1" or "550 5.1.10" stay readable.
        return (string) preg_replace_callback(
            '/\+?\d[\d\s\/().-]{5,}\d/u',
            static fn (array $m): string => preg_match('/^\d{3}\s+\d\.\d{1,3}\.\d{1,3}$/', $m[0]) !== 1 && preg_match_all('/\d/', $m[0]) >= 7 ? '[number]' : $m[0],
            $text,
        );
    }

    /**
     * Context for a throwable outside a single message (e.g. bulk sends):
     * class, masked message and location — never the throwable itself, whose
     * message or trace arguments may carry personal data.
     *
     * @return array<string, mixed>
     */
    public static function forThrowable(Throwable $t): array
    {
        return [
            'error_code' => $t->getCode(),
            'exception_class' => $t::class,
            'exception_message' => self::redact($t->getMessage()),
            'exception_location' => $t->getFile().':'.$t->getLine(),
            'trace' => self::frames($t),
        ];
    }

    /**
     * Stack frames as "Class::function (file:line)" — never the arguments.
     *
     * @return list<string>
     */
    private static function frames(Throwable $t): array
    {
        $frames = [];

        foreach (array_slice($t->getTrace(), 0, self::MAX_TRACE_FRAMES) as $frame) {
            $function = isset($frame['class'])
                ? $frame['class'].($frame['type'] ?? '::').$frame['function']
                : $frame['function'];

            $frames[] = $function.' ('.($frame['file'] ?? '?').':'.($frame['line'] ?? '?').')';
        }

        return $frames;
    }
}
