<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Inspects an InboundMessage and produces a BounceReport.
 *
 * Detection order:
 *   1. RFC 3464 DSN     — multipart/report; report-type=delivery-status,
 *                         OR a message/delivery-status part anywhere.
 *   2. RFC 5965 ARF     — multipart/report; report-type=feedback-report,
 *                         OR a message/feedback-report part.
 *   3. Auto-reply       — Auto-Submitted header or vacation-style subject.
 *   4. Reply            — Has In-Reply-To / References and looks human-authored.
 *   5. Unknown          — anything else.
 */
final class BounceClassifier
{
    public function classify(InboundMessage $message): BounceReport
    {
        if ($this->isComplaint($message)) {
            return $this->buildComplaintReport($message);
        }

        if ($this->isBounce($message)) {
            return $this->buildBounceReport($message);
        }

        if ($this->isAutoReply($message)) {
            return new BounceReport(
                classification: BounceClassification::AutoReply,
                originalCorrelationId: $this->extractCorrelationFromHeaders($message->headers),
                originalMessageId: $this->extractInReplyTo($message),
            );
        }

        if ($this->looksLikeReply($message)) {
            return new BounceReport(
                classification: BounceClassification::Reply,
                originalCorrelationId: $this->extractCorrelationFromHeaders($message->headers),
                originalMessageId: $this->extractInReplyTo($message),
            );
        }

        return new BounceReport(classification: BounceClassification::Unknown);
    }

    private function isComplaint(InboundMessage $message): bool
    {
        $ct = mb_strtolower((string) $message->header('content-type'));
        if (str_contains($ct, 'multipart/report') && str_contains($ct, 'feedback-report')) {
            return true;
        }

        return $message->hasPartType('message/feedback-report');
    }

    private function isBounce(InboundMessage $message): bool
    {
        $ct = mb_strtolower((string) $message->header('content-type'));
        if (str_contains($ct, 'multipart/report') && str_contains($ct, 'delivery-status')) {
            return true;
        }
        if ($message->hasPartType('message/delivery-status')) {
            return true;
        }

        // Heuristic fallback: well-known mailer-daemon senders + subject lines.
        $from = mb_strtolower($message->from());
        if (
            str_contains($from, 'mailer-daemon')
            || str_contains($from, 'postmaster@')
        ) {
            return true;
        }

        $subject = mb_strtolower($message->subject());

        return str_contains($subject, 'undelivered mail returned')
            || str_contains($subject, 'mail delivery failed')
            || str_contains($subject, 'delivery status notification (failure)');
    }

    private function isAutoReply(InboundMessage $message): bool
    {
        $autoSubmitted = mb_strtolower((string) $message->header('auto-submitted'));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }
        if ($message->header('x-autoreply') !== null) {
            return true;
        }
        if ($message->header('x-autorespond') !== null) {
            return true;
        }
        $subject = mb_strtolower($message->subject());

        return str_contains($subject, 'out of office')
            || str_contains($subject, 'abwesenheit')
            || str_contains($subject, 'vacation');
    }

    private function looksLikeReply(InboundMessage $message): bool
    {
        return $message->header('in-reply-to') !== null
            || $message->header('references') !== null;
    }

    private function buildBounceReport(InboundMessage $message): BounceReport
    {
        $dsnPart = $message->firstPartByType('message/delivery-status');
        $dsnFields = $dsnPart instanceof InboundMimePart ? $this->parseDsnFields($dsnPart->body) : [];

        $statusCode = $dsnFields['status'] ?? null;
        $diagnosticCode = $dsnFields['diagnostic-code'] ?? null;
        $finalRecipient = $dsnFields['final-recipient'] ?? ($dsnFields['original-recipient'] ?? null);
        $recipients = is_string($finalRecipient) && $finalRecipient !== ''
            ? [$this->stripRecipientPrefix($finalRecipient)]
            : [];

        $returnedHeaders = $this->extractReturnedMessageHeaders($message);
        $correlationId = $this->extractCorrelationFromHeaders($returnedHeaders)
            ?? $this->extractCorrelationFromHeaders($message->headers);
        $originalMessageId = $this->firstHeader($returnedHeaders, 'message-id')
            ?? $this->extractInReplyTo($message);
        $originalSesMessageId = $this->firstHeader($returnedHeaders, 'x-ses-message-id');
        $originalSubject = $this->firstHeader($returnedHeaders, 'subject');

        $arrival = $this->parseDate($dsnFields['arrival-date'] ?? $message->header('date'));

        [$classification, $subType] = $this->classifyByStatus($statusCode, $diagnosticCode);

        return new BounceReport(
            classification: $classification,
            statusCode: $statusCode,
            diagnosticCode: $diagnosticCode,
            recipients: $recipients,
            originalCorrelationId: $correlationId,
            originalMessageId: $originalMessageId,
            originalSesMessageId: $originalSesMessageId,
            originalSubject: $originalSubject,
            bouncedAt: $arrival,
            rawDsnFields: $dsnFields,
            subType: $subType,
        );
    }

    private function buildComplaintReport(InboundMessage $message): BounceReport
    {
        $arfPart = $message->firstPartByType('message/feedback-report');
        $arfFields = $arfPart instanceof InboundMimePart ? $this->parseDsnFields($arfPart->body) : [];

        $originalRcpt = $arfFields['original-rcpt-to'] ?? $arfFields['original-mail-from'] ?? null;
        $recipients = is_string($originalRcpt) && $originalRcpt !== ''
            ? [$this->stripRecipientPrefix($originalRcpt)]
            : [];

        $returnedHeaders = $this->extractReturnedMessageHeaders($message);
        $correlationId = $this->extractCorrelationFromHeaders($returnedHeaders)
            ?? $this->extractCorrelationFromHeaders($message->headers);
        $originalMessageId = $this->firstHeader($returnedHeaders, 'message-id');
        $originalSesMessageId = $this->firstHeader($returnedHeaders, 'x-ses-message-id');
        $originalSubject = $this->firstHeader($returnedHeaders, 'subject');

        $arrival = $this->parseDate($arfFields['arrival-date'] ?? $message->header('date'));

        return new BounceReport(
            classification: BounceClassification::Complaint,
            statusCode: null,
            diagnosticCode: null,
            recipients: $recipients,
            originalCorrelationId: $correlationId,
            originalMessageId: $originalMessageId,
            originalSesMessageId: $originalSesMessageId,
            originalSubject: $originalSubject,
            bouncedAt: $arrival,
            rawDsnFields: $arfFields,
            subType: $arfFields['feedback-type'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseDsnFields(string $body): array
    {
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $body) ?? $body;
        $lines = preg_split("/\r?\n/", $unfolded) ?: [];
        $fields = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = mb_strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));
            // DSN repeats Final-Recipient / Diagnostic-Code per recipient block — last one wins
            // for our flat representation. Callers needing per-recipient detail should walk
            // recipients[] from the original SNS payload structure instead.
            $fields[$name] = $value;
        }

        return $fields;
    }

    /**
     * The returned-message part of a DSN/ARF carries the original headers of the
     * bounced message. We look in two places: message/rfc822 (full message) and
     * text/rfc822-headers (headers-only variant).
     *
     * @return array<string, list<string>>
     */
    private function extractReturnedMessageHeaders(InboundMessage $message): array
    {
        foreach (['message/rfc822', 'text/rfc822-headers'] as $type) {
            $part = $message->firstPartByType($type);
            if (! $part instanceof InboundMimePart) {
                continue;
            }
            $body = $part->body;
            // For message/rfc822 we want only the embedded headers (before its blank line).
            if ($type === 'message/rfc822') {
                $sep = strpos($body, "\r\n\r\n");
                if ($sep === false) {
                    $sep = strpos($body, "\n\n");
                    $body = $sep === false ? $body : substr($body, 0, $sep);
                } else {
                    $body = substr($body, 0, $sep);
                }
            }
            $parsed = $this->parseRfcHeaders($body);
            if ($parsed !== []) {
                return $parsed;
            }
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseRfcHeaders(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], ["\n", "\n"], $raw);
        $unfolded = preg_replace("/\n[ \t]+/", ' ', $raw) ?? $raw;
        $lines = explode("\n", $unfolded);
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = mb_strtolower(trim(substr($line, 0, $colon)));
            $value = ltrim(substr($line, $colon + 1));
            $headers[$name] ??= [];
            $headers[$name][] = $value;
        }

        return $headers;
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function firstHeader(array $headers, string $name): ?string
    {
        $value = $headers[mb_strtolower($name)][0] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }
        if ($name === 'message-id' || mb_strtolower($name) === 'message-id') {
            return $this->normalizeMessageId($value);
        }

        return $value;
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function extractCorrelationFromHeaders(array $headers): ?string
    {
        $direct = $headers[mb_strtolower('X-Topoff-Message-Id')][0] ?? null;
        if (is_string($direct) && $direct !== '') {
            return trim($direct);
        }

        $messageId = $headers['message-id'][0] ?? null;
        if (is_string($messageId)) {
            $normalized = $this->normalizeMessageId($messageId);
            $uuid = $this->extractUuid($normalized ?? '');
            if ($uuid !== null) {
                return $uuid;
            }
        }

        // In-Reply-To / References might point at our originally-stamped ID.
        foreach (['in-reply-to', 'references'] as $key) {
            $value = $headers[$key][0] ?? null;
            if (! is_string($value)) {
                continue;
            }
            $uuid = $this->extractUuid($value);
            if ($uuid !== null) {
                return $uuid;
            }
        }

        return null;
    }

    private function extractInReplyTo(InboundMessage $message): ?string
    {
        $value = $message->header('in-reply-to');
        if (! is_string($value)) {
            return null;
        }

        return $this->normalizeMessageId($value);
    }

    private function normalizeMessageId(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            return $m[1];
        }

        return $value;
    }

    private function extractUuid(string $value): ?string
    {
        if (preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[1-7][0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}/i', $value, $m)) {
            return mb_strtolower($m[0]);
        }

        return null;
    }

    private function stripRecipientPrefix(string $value): string
    {
        // RFC 3464 final-recipient looks like "rfc822; user@example.com".
        if (str_contains($value, ';')) {
            $value = explode(';', $value, 2)[1];
        }

        return mb_strtolower(trim($value));
    }

    private function parseDate(?string $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Map RFC 3464 status / SMTP-code to (classification, subType).
     *
     * @return array{0: BounceClassification, 1: ?string}
     */
    private function classifyByStatus(?string $statusCode, ?string $diagnosticCode): array
    {
        if (is_string($statusCode) && $statusCode !== '') {
            if (str_starts_with($statusCode, '5.')) {
                return [BounceClassification::HardBounce, $this->subTypeFromStatus($statusCode)];
            }
            if (str_starts_with($statusCode, '4.')) {
                return [BounceClassification::SoftBounce, $this->subTypeFromStatus($statusCode)];
            }
            if (str_starts_with($statusCode, '2.')) {
                return [BounceClassification::Unknown, null];
            }
        }

        // Diagnostic-code fallback (e.g. "smtp; 550 5.1.1 ...").
        if (is_string($diagnosticCode)) {
            if (preg_match('/\b(550|553|521|556)\b/', $diagnosticCode)) {
                return [BounceClassification::HardBounce, 'General'];
            }
            if (preg_match('/\b(421|450|451|452)\b/', $diagnosticCode)) {
                return [BounceClassification::SoftBounce, 'General'];
            }
        }

        // Default — DSN with no usable status → conservatively soft.
        return [BounceClassification::SoftBounce, 'General'];
    }

    private function subTypeFromStatus(string $statusCode): string
    {
        return match ($statusCode) {
            '5.1.1', '5.1.0' => 'NoEmail',
            '5.1.2' => 'BadDomain',
            '5.2.2' => 'MailboxFull',
            '5.2.3', '5.3.4' => 'MessageTooLarge',
            '5.7.1' => 'Suppressed',
            '4.2.2' => 'MailboxFull',
            '4.4.1', '4.4.7' => 'ConnectionFailure',
            default => 'General',
        };
    }
}
