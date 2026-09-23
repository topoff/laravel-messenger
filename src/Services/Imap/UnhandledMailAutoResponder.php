<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;
use Topoff\Messenger\Mail\ForwardedInboundMail;
use Topoff\Messenger\Mail\NoReplyAutoResponseMail;

/**
 * Answers an inbound email that nobody handled with an automatic notice to the
 * sender: this mailbox is send-only and not read, please write to the
 * monitored contact address (messenger.imap.auto_reply.contact_address).
 *
 * When enabled, this REPLACES the forward to a human mailbox
 * (InboundMailForwarder) for unhandled replies and unknown mail — the goal is
 * that senders learn the right address instead of a human triaging a no-reply
 * inbox. Mail a guard refuses to answer is logged only; the original stays in
 * the mailbox and follows the normal per-classification IMAP move rules.
 *
 * Guards, each of which suppresses the response:
 *   • our own marker headers (auto-response or forward) — loop protection
 *   • mail the host flagged as spam — answering spam creates backscatter
 *   • mail from one of our own addresses — loop protection
 *   • automated senders (Precedence bulk/list, List-* headers, mailer-daemon,
 *     no-reply-style local parts) — machines don't read answers, loops do
 *   • a per-sender throttle (default 24h) — one notice is enough, and it caps
 *     the damage if an unrecognized responder answers our answer
 *
 * The response deliberately quotes nothing from the original mail, so our
 * sending path can't be abused as a backscatter reflector.
 *
 * Sending never throws: a failed response is logged and the sweep continues.
 */
class UnhandledMailAutoResponder
{
    public function __construct(private readonly InboundMailForwarder $forwarder) {}

    /**
     * Enabled only with the explicit flag AND a monitored contact address —
     * an auto-response pointing nowhere would strand senders.
     */
    public function isEnabled(): bool
    {
        return (bool) config('messenger.imap.auto_reply.enabled')
            && $this->configuredAddress('contact_address') !== null;
    }

    /**
     * @return bool whether an automatic response was actually dispatched
     */
    public function respond(InboundMessage $inbound, string $inboxKey, string $reason): bool
    {
        $contact = $this->configuredAddress('contact_address');
        if (! $this->isEnabled() || $contact === null) {
            return false;
        }

        if ($this->carriesOwnMarker($inbound)) {
            Log::info('UnhandledMailAutoResponder: skipping our own mail (loop protection)', [
                'inbox_key' => $inboxKey,
                'subject' => $inbound->subject(),
            ]);

            return false;
        }

        if ($this->forwarder->looksLikeSpam($inbound)) {
            Log::info('UnhandledMailAutoResponder: inbound message flagged as spam, not answering', [
                'inbox_key' => $inboxKey,
                'from' => $inbound->from(),
            ]);

            return false;
        }

        $to = $this->senderAddress($inbound);
        if ($to === null) {
            Log::info('UnhandledMailAutoResponder: no valid sender address, not answering', [
                'inbox_key' => $inboxKey,
                'from' => $inbound->from(),
            ]);

            return false;
        }

        if ($this->forwarder->comesFromOwnAddress($inbound) || $this->isOwnAddress($to)) {
            Log::info('UnhandledMailAutoResponder: inbound message from one of our own addresses, not answering (loop protection)', [
                'inbox_key' => $inboxKey,
                'from' => $to,
            ]);

            return false;
        }

        if ($this->looksLikeAutomatedSender($inbound, $to)) {
            Log::info('UnhandledMailAutoResponder: inbound message looks automated, not answering', [
                'inbox_key' => $inboxKey,
                'from' => $to,
            ]);

            return false;
        }

        $throttleKey = $this->throttleKey($inboxKey, $to);
        if ($throttleKey !== null && ! Cache::add($throttleKey, true, now()->addHours($this->throttleHours()))) {
            Log::info('UnhandledMailAutoResponder: sender already answered recently, not answering again', [
                'inbox_key' => $inboxKey,
                'from' => $to,
            ]);

            return false;
        }

        try {
            $mailable = new NoReplyAutoResponseMail(
                noReplyAddress: $this->noReplyAddress($inbound),
                contactAddress: $contact,
                originalSubject: $this->decodeHeader($inbound->subject()),
                originalMessageId: $this->originalMessageId($inbound),
            );

            $mailable->to($to);
            $mailable->replyTo($contact);

            $from = $this->fromAddress();
            if ($from !== null) {
                $mailable->from($from);
            }

            $bcc = $this->configuredAddress('bcc');
            if ($bcc !== null) {
                $mailable->bcc($bcc);
            }

            Mail::send($mailable);

            Log::info('UnhandledMailAutoResponder: answered unhandled inbound message', [
                'inbox_key' => $inboxKey,
                'reason' => $reason,
                'to' => $to,
            ]);

            return true;
        } catch (Throwable $e) {
            // Release the throttle slot: a transient send failure must not
            // suppress the notice for a full throttle window.
            if ($throttleKey !== null) {
                Cache::forget($throttleKey);
            }

            Log::error('UnhandledMailAutoResponder: failed to answer inbound message', [
                'inbox_key' => $inboxKey,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'file' => $e->getFile().':'.$e->getLine(),
            ]);

            return false;
        }
    }

    /**
     * True for mail this package itself produced — an auto-response bouncing
     * back, or one of our own forwards returning.
     */
    private function carriesOwnMarker(InboundMessage $inbound): bool
    {
        return $inbound->header(NoReplyAutoResponseMail::MARKER_HEADER) !== null
            || $inbound->header(ForwardedInboundMail::MARKER_HEADER) !== null;
    }

    private function isOwnAddress(string $address): bool
    {
        $own = array_filter(array_map(
            static fn (?string $value): string => mb_strtolower(trim((string) $value)),
            [
                $this->configuredAddress('contact_address'),
                $this->configuredAddress('from'),
            ],
        ));

        return in_array($address, $own, true);
    }

    /**
     * Automated senders never read an answer — but their systems may answer
     * ours, and two automats answering each other is a mail loop. The
     * classifier already drops declared auto-replies (Auto-Submitted etc.);
     * this guard covers list mail and daemon/no-reply-style senders that
     * still land in the Unknown/Reply buckets.
     */
    private function looksLikeAutomatedSender(InboundMessage $inbound, string $from): bool
    {
        $precedence = mb_strtolower(trim((string) $inbound->header('precedence')));
        if (in_array($precedence, ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }

        if ($inbound->header('list-id') !== null || $inbound->header('list-unsubscribe') !== null) {
            return true;
        }

        if ($inbound->header('x-auto-response-suppress') !== null) {
            return true;
        }

        $localPart = strstr($from, '@', true) ?: $from;

        return array_any(['mailer-daemon', 'postmaster', 'no-reply', 'noreply', 'do-not-reply', 'donotreply', 'bounce'], fn ($marker) => str_starts_with($localPart, (string) $marker));
    }

    /**
     * The address our notice goes to: the original sender's From.
     *
     * Deliberately NOT the inbound Reply-To header — that header is fully
     * attacker-controlled, and honoring it would let a spoofed mail direct
     * our automatic answers at an arbitrary third address.
     */
    private function senderAddress(InboundMessage $inbound): ?string
    {
        $address = $this->extractEmailAddress($inbound->from());
        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
            return $address;
        }

        return null;
    }

    /**
     * The send-only address named in the notice: the original To when it is a
     * plain address, otherwise our own sender identity as the best description
     * of "the address you wrote to".
     */
    private function noReplyAddress(InboundMessage $inbound): string
    {
        $to = $this->extractEmailAddress((string) $inbound->header('to'));
        if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL) !== false) {
            return $to;
        }

        return (string) $this->fromAddress();
    }

    /**
     * Sender identity of the notice. Falls back to the forward identity, then
     * the application's global mail.from address (SES / DMARC aligned).
     */
    private function fromAddress(): ?string
    {
        foreach ([
            $this->configuredAddress('from'),
            trim((string) config('messenger.imap.forward.from')),
            trim((string) config('mail.from.address')),
        ] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    private function originalMessageId(InboundMessage $inbound): ?string
    {
        $messageId = trim((string) $inbound->header('message-id'), " \t<>");

        return $messageId === '' ? null : $messageId;
    }

    private function throttleKey(string $inboxKey, string $sender): ?string
    {
        if ($this->throttleHours() <= 0) {
            return null;
        }

        return 'messenger:imap:auto_reply:'.sha1($inboxKey.'|'.$sender);
    }

    private function throttleHours(): int
    {
        return (int) config('messenger.imap.auto_reply.throttle_hours', 24);
    }

    private function configuredAddress(string $key): ?string
    {
        $value = trim((string) config('messenger.imap.auto_reply.'.$key));

        return $value === '' ? null : $value;
    }

    private function extractEmailAddress(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m) === 1) {
            return mb_strtolower(trim($m[1]));
        }

        return mb_strtolower(trim($value));
    }

    /**
     * Inbound headers are stored raw, so RFC 2047 encoded words ("=?UTF-8?B?…?=")
     * still need decoding before they go into the response subject.
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
