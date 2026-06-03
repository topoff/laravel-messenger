<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;
use Topoff\Messenger\Events\ImapMessageProcessedEvent;
use Topoff\Messenger\Events\MessageComplaintEvent;
use Topoff\Messenger\Events\MessagePermanentBouncedEvent;
use Topoff\Messenger\Events\MessageReplyReceivedEvent;
use Topoff\Messenger\Events\MessageTransientBouncedEvent;
use Topoff\Messenger\Models\Message;

/**
 * Orchestrates one pass over an inbox. For each message:
 *   1. Reserve a fingerprint (idempotency) — skip on duplicate
 *   2. Parse the raw RFC 822 source
 *   3. Classify (DSN / ARF / AutoReply / Reply / Unknown)
 *   4. Match against tracked Messages
 *   5. Persist bounce / complaint state into Message.tracking_meta
 *   6. Dispatch higher-level events (PermanentBounced / TransientBounced /
 *      Complaint / ReplyReceived) plus the low-level ImapMessageProcessedEvent
 *   7. Tell the source what classification we landed on so it can move/flag/delete
 *
 * Bounce handling intentionally mirrors RecordBounceJob's tracking_meta layout
 * (failures[], success flag, sns_message_bounce surrogate as imap_message_bounce)
 * so consumers of the existing events don't see any structural difference.
 *
 * This class never writes to the SES suppression list — see docs.
 */
class ImapBounceProcessor
{
    public function __construct(
        private readonly InboundMessageParser $parser,
        private readonly BounceClassifier $classifier,
        private readonly MessageMatcher $matcher,
        private readonly ProcessedMessageTracker $tracker,
    ) {}

    public function process(InboundMessageSource $source, int $limit = 200): ProcessingResult
    {
        $result = new ProcessingResult($source->inboxKey());

        foreach ($source->fetch($limit) as $envelope) {
            $uid = (string) $envelope['uid'];
            $raw = (string) $envelope['raw'];

            if ($raw === '') {
                $result->errors++;

                continue;
            }

            try {
                $this->processOne($source, $uid, $raw, $result);
            } catch (Throwable $e) {
                $result->errors++;
                Log::error('ImapBounceProcessor: failed to process inbound message', [
                    'inbox_key' => $source->inboxKey(),
                    'imap_uid' => $uid,
                    'error' => $e->getMessage(),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);
            }
        }

        return $result;
    }

    private function processOne(
        InboundMessageSource $source,
        string $uid,
        string $raw,
        ProcessingResult $result,
    ): void {
        $inboxKey = $source->inboxKey();
        $fingerprint = $this->tracker->fingerprint($raw);

        if ($this->tracker->isProcessed($inboxKey, $fingerprint)) {
            $result->skippedDuplicate++;

            return;
        }

        $inbound = $this->parser->parse($raw);
        $report = $this->classifier->classify($inbound);
        $matches = $this->matcher->match($report);

        $reserved = $this->tracker->reserve($inboxKey, $fingerprint, $uid, $report->classification);
        if (! $reserved) {
            // Another worker beat us to it — safe to skip.
            $result->skippedDuplicate++;

            return;
        }

        $matchedIds = $matches->pluck('id')->all();
        $this->tracker->recordMatches($inboxKey, $fingerprint, $matchedIds);

        match ($report->classification) {
            BounceClassification::HardBounce,
            BounceClassification::SoftBounce => $this->handleBounce($result, $inbound, $report, $matches),
            BounceClassification::Complaint => $this->handleComplaint($result, $inbound, $report, $matches),
            BounceClassification::Reply => $this->handleReply($result, $inbound, $report, $matches, $inboxKey),
            BounceClassification::AutoReply => $result->autoReplies++,
            BounceClassification::Unknown => $result->unknown++,
        };

        Event::dispatch(new ImapMessageProcessedEvent(
            inboxKey: $inboxKey,
            imapUid: $uid,
            classification: $report->classification,
            matchedMessageIds: $matchedIds,
        ));

        $source->markProcessed($uid, $report->classification);

        if ($matches->isEmpty()) {
            Log::info('ImapBounceProcessor: no matching tracked message', [
                'inbox_key' => $inboxKey,
                'imap_uid' => $uid,
                'classification' => $report->classification->value,
                'original_correlation_id' => $report->originalCorrelationId,
                'original_message_id' => $report->originalMessageId,
                'recipients' => $report->recipients,
                'subject' => $inbound->subject(),
            ]);
        }
    }

    /**
     * @param  Collection<int, Message>  $matches
     */
    private function handleBounce(
        ProcessingResult $result,
        InboundMessage $inbound,
        BounceReport $report,
        $matches,
    ): void {
        if ($report->classification === BounceClassification::HardBounce) {
            $result->hardBounces++;
        } else {
            $result->softBounces++;
        }

        foreach ($matches as $message) {
            $this->applyBounceToMessage($message, $report, $inbound);

            if ($report->classification === BounceClassification::HardBounce) {
                Event::dispatch(new MessagePermanentBouncedEvent($message));
            } else {
                Event::dispatch(new MessageTransientBouncedEvent(
                    bounceSubType: $report->subType ?? 'General',
                    diagnosticCode: $report->diagnosticCode ?? '',
                    message: $message,
                ));
            }
        }
    }

    private function applyBounceToMessage(Message $message, BounceReport $report, InboundMessage $inbound): void
    {
        $meta = collect($message->tracking_meta ?: []);
        $failures = collect($meta->get('failures', []));

        $failures->push(array_filter([
            'source' => 'imap',
            'status' => $report->statusCode,
            'diagnostic_code' => $report->diagnosticCode,
            'recipient' => $report->recipients[0] ?? null,
            'sub_type' => $report->subType,
            'arrived_at' => $report->bouncedAt?->toIso8601String(),
        ], fn ($v): bool => $v !== null && $v !== ''));

        $meta->put('failures', $failures->toArray());

        // Mirror SNS-path semantics: only flip success to false if no prior delivery confirmation exists.
        if (! $meta->has('success')) {
            $meta->put('success', false);
        }

        $meta->put('imap_message_bounce', [
            'classification' => $report->classification->value,
            'subject' => $inbound->subject(),
            'from' => $inbound->from(),
            'dsn_fields' => $report->rawDsnFields,
        ]);

        $message->tracking_meta = $meta->toArray();
        if ($report->bouncedAt !== null && $message->bounced_at === null) {
            $message->bounced_at = Carbon::instance($report->bouncedAt->toDateTimeImmutable());
        }
        $message->save();
    }

    /**
     * @param  Collection<int, Message>  $matches
     */
    private function handleComplaint(
        ProcessingResult $result,
        InboundMessage $inbound,
        BounceReport $report,
        $matches,
    ): void {
        $result->complaints++;

        foreach ($matches as $message) {
            $meta = collect($message->tracking_meta ?: []);
            $meta->put('complaint', true);
            $meta->put('success', false);
            $meta->put('complaint_type', $report->subType);
            $meta->put('imap_message_complaint', [
                'subject' => $inbound->subject(),
                'from' => $inbound->from(),
                'arf_fields' => $report->rawDsnFields,
            ]);

            $message->tracking_meta = $meta->toArray();
            $message->save();

            Event::dispatch(new MessageComplaintEvent($message));
        }
    }

    /**
     * @param  Collection<int, Message>  $matches
     */
    private function handleReply(
        ProcessingResult $result,
        InboundMessage $inbound,
        BounceReport $report,
        $matches,
        string $inboxKey,
    ): void {
        $result->replies++;

        $matched = $matches->first();

        Event::dispatch(new MessageReplyReceivedEvent(
            message: $matched,
            inboxKey: $inboxKey,
            fromAddress: $this->extractEmailAddress($inbound->from()),
            subject: $inbound->subject(),
            textBody: $this->extractTextBody($inbound),
            htmlBody: $inbound->firstPartByType('text/html')?->body,
            rawHeaders: $inbound->headers,
            attachments: $this->extractAttachmentManifest($inbound),
        ));
    }

    private function extractEmailAddress(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            return mb_strtolower(trim($m[1]));
        }

        return mb_strtolower(trim($value));
    }

    private function extractTextBody(InboundMessage $inbound): string
    {
        $part = $inbound->firstPartByType('text/plain');

        return $part === null ? '' : $part->body;
    }

    /**
     * @return list<array{filename: string, mime: string, size: int}>
     */
    private function extractAttachmentManifest(InboundMessage $inbound): array
    {
        $attachments = [];
        foreach ($inbound->parts as $part) {
            $disposition = (string) $part->header('content-disposition');
            if (! str_contains(mb_strtolower($disposition), 'attachment')) {
                continue;
            }
            $filename = null;
            if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m)) {
                $filename = $m[1];
            }
            $attachments[] = [
                'filename' => $filename ?? 'unknown',
                'mime' => $part->contentType,
                'size' => strlen($part->body),
            ];
        }

        return $attachments;
    }
}
