<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Topoff\Messenger\Mail\ForwardedInboundMail;

/**
 * Forwards an inbound email that nobody handled to a human mailbox
 * (messenger.imap.forward.unhandled_to), as a real outgoing email.
 *
 * The forward keeps the original readable and answerable:
 *   • From      — our own configured sender identity (SES / DMARC aligned)
 *   • Reply-To  — the original sender, so "reply" in the mail client reaches them
 *   • Subject   — "Fwd: <original subject>"
 *   • Body      — the original text body inline, with a forwarding header block
 *   • Files     — the original attachments plus the untouched original as .eml
 *   • Header    — X-Topoff-Forwarded, so our own forwards are recognizable
 *
 * Two guards run before every send: mail we already forwarded once (marker
 * header) is never forwarded again, and mail the mail host flagged as spam is
 * logged only — forwarding spam over our own sending path would damage the
 * sender reputation.
 *
 * Sending never throws: a failed forward is logged and the sweep continues, so
 * the message stays in the inbox instead of aborting the run.
 */
class InboundMailForwarder
{
    /**
     * @return bool whether a forwarding mail was actually dispatched
     */
    public function forward(InboundMessage $inbound, string $rawSource, string $inboxKey, string $reason): bool
    {
        $to = $this->configuredAddress('unhandled_to');
        if ($to === null) {
            return false;
        }

        if ($this->wasForwardedByUs($inbound)) {
            Log::info('InboundMailForwarder: skipping own forward (loop protection)', [
                'inbox_key' => $inboxKey,
                'subject' => $this->decodeHeader($inbound->subject()),
            ]);

            return false;
        }

        if ($this->looksLikeSpam($inbound)) {
            Log::info('InboundMailForwarder: inbound message flagged as spam, not forwarding', [
                'inbox_key' => $inboxKey,
                'from' => $this->decodeHeader($inbound->from()),
                'subject' => $this->decodeHeader($inbound->subject()),
            ]);

            return false;
        }

        if ($this->comesFromOwnAddress($inbound)) {
            Log::info('InboundMailForwarder: inbound message from one of our own addresses, not forwarding (loop protection)', [
                'inbox_key' => $inboxKey,
                'from' => $this->decodeHeader($inbound->from()),
                'subject' => $this->decodeHeader($inbound->subject()),
            ]);

            return false;
        }

        try {
            $mailable = new ForwardedInboundMail(
                originalFrom: $this->decodeHeader($inbound->from()),
                originalTo: $this->decodeHeader((string) $inbound->header('to')),
                originalDate: $this->decodeHeader((string) $inbound->header('date')),
                originalSubject: $this->decodeHeader($inbound->subject()),
                originalBody: $this->extractBody($inbound),
                inboxKey: $inboxKey,
                reason: $reason,
            );

            $mailable->to($to);

            $from = $this->configuredAddress('from');
            if ($from !== null) {
                $mailable->from($from);
            }

            $bcc = $this->configuredAddress('bcc');
            if ($bcc !== null) {
                $mailable->bcc($bcc);
            }

            $replyTo = $this->replyToAddress($inbound);
            if ($replyTo !== null) {
                $mailable->replyTo($replyTo);
            }

            foreach ($this->attachmentParts($inbound) as $attachment) {
                $mailable->attachData($attachment['data'], $attachment['filename'], ['mime' => $attachment['mime']]);
            }

            $mailable->attachData($rawSource, 'original-message.eml', ['mime' => 'message/rfc822']);

            Mail::send($mailable);

            Log::info('InboundMailForwarder: forwarded unhandled inbound message', [
                'inbox_key' => $inboxKey,
                'reason' => $reason,
                'to' => $to,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('InboundMailForwarder: failed to forward inbound message', [
                'inbox_key' => $inboxKey,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * Whether forwarding is configured at all. Without a target the processor
     * keeps its previous log-only behavior.
     */
    public function isEnabled(): bool
    {
        return $this->configuredAddress('unhandled_to') !== null;
    }

    /**
     * True for mail this package forwarded before — a forward of a forward would
     * be a loop (e.g. our own forward bouncing back into the reply-to inbox).
     */
    public function wasForwardedByUs(InboundMessage $inbound): bool
    {
        return $inbound->header(ForwardedInboundMail::MARKER_HEADER) !== null;
    }

    /**
     * Spam guard: match the mail host's spam headers against the configured
     * markers (case-insensitive prefix match on the header value).
     */
    public function looksLikeSpam(InboundMessage $inbound): bool
    {
        /** @var array<string, mixed> $configured */
        $configured = (array) config('messenger.imap.forward.spam_headers', []);

        foreach ($configured as $header => $markers) {
            foreach ($inbound->headersAll((string) $header) as $value) {
                $normalized = mb_strtolower(trim($value));
                if ($normalized === '') {
                    continue;
                }

                foreach ((array) $markers as $marker) {
                    $marker = mb_strtolower(trim((string) $marker));
                    if ($marker !== '' && str_starts_with($normalized, $marker)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function configuredAddress(string $key): ?string
    {
        $value = trim((string) config('messenger.imap.forward.'.$key));

        return $value === '' ? null : $value;
    }

    /**
     * Second loop guard besides the marker header: a responder that generates a
     * brand-new mail from one of our own addresses (forward target, sending
     * identities) carries no marker and gets a fresh fingerprint on every turn —
     * forwarding it back would loop every sweep. Own mail never needs forwarding.
     */
    private function comesFromOwnAddress(InboundMessage $inbound): bool
    {
        $from = $this->extractEmailAddress($inbound->from());
        if ($from === '') {
            return false;
        }

        $own = [
            $this->configuredAddress('unhandled_to'),
            $this->configuredAddress('from'),
            (string) config('mail.from.address'),
        ];
        foreach ((array) config('messenger.ses_sns.sending.identities', []) as $identity) {
            $own[] = (string) ($identity['mail_from_address'] ?? '');
            $own[] = (string) ($identity['reply_to_address'] ?? '');
        }

        $own = array_filter(array_map(
            static fn (?string $address): string => mb_strtolower(trim((string) $address)),
            $own
        ));

        return in_array($from, $own, true);
    }

    /**
     * The address a human answer should reach: the original sender's From.
     *
     * Deliberately NOT the inbound Reply-To header — that header is fully
     * attacker-controlled, and honoring it would let a spoofed mail silently
     * redirect the team's answers to an arbitrary third address.
     */
    private function replyToAddress(InboundMessage $inbound): ?string
    {
        $address = $this->extractEmailAddress($inbound->from());
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
            return $address;
        }

        return null;
    }

    private function extractEmailAddress(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m) === 1) {
            return mb_strtolower(trim($m[1]));
        }

        return mb_strtolower(trim($value));
    }

    /**
     * Inline body of the forward. Prefers the plain-text part; falls back to a
     * de-tagged HTML part so the forward is never empty when only HTML was sent.
     */
    private function extractBody(InboundMessage $inbound): string
    {
        $text = $inbound->firstPartByType('text/plain')?->body;
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        $html = $inbound->firstPartByType('text/html')?->body;
        if (is_string($html) && trim($html) !== '') {
            return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return '';
    }

    /**
     * Original attachments including their decoded payloads.
     *
     * @return list<array{filename: string, mime: string, data: string}>
     */
    private function attachmentParts(InboundMessage $inbound): array
    {
        $attachments = [];

        foreach ($inbound->parts as $index => $part) {
            $disposition = (string) $part->header('content-disposition');
            if (! str_contains(mb_strtolower($disposition), 'attachment')) {
                continue;
            }

            $filename = null;
            if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
                $filename = $this->sanitizeFilename($this->decodeHeader($m[1]));
            }

            $attachments[] = [
                'filename' => $filename ?? 'attachment-'.($index + 1),
                'mime' => $part->contentType,
                'data' => $part->body,
            ];
        }

        return $attachments;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = trim(str_replace(['/', '\\', "\0", "\r", "\n"], '-', $filename));

        return $filename === '' ? 'attachment' : $filename;
    }

    /**
     * Inbound headers are stored raw, so RFC 2047 encoded words ("=?UTF-8?B?…?=")
     * still need decoding before they go into a human-readable forward.
     */
    private function decodeHeader(string $value): string
    {
        if ($value === '' || ! str_contains($value, '=?')) {
            return trim($value);
        }

        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($decoded === false ? $value : $decoded);
    }
}
