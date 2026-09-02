<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapts a Webklex IMAP Folder to InboundMessageSource. We type-hint the
 * folder as `object` to avoid a hard link to the optional dependency;
 * the Webklex API used here is:
 *
 *   $folder->messages()->unseen()->get()
 *   $folder->messages()->since($carbonDate)->unseen()->get()
 *   $message->getRawBody(): string          — body ONLY, headers stripped
 *   $message->getHeader()?->raw: string     — the raw RFC 822 header block
 *   $message->getUid(): int|string
 *   $message->setFlag('Seen' | …): bool
 *   $message->move(string $targetFolder): bool
 *   $message->delete(): bool
 *
 * The `afterProcess` map controls what to do post-classification per category;
 * `folders` map controls move targets.
 */
class WebklexInboundMessageSource implements InboundMessageSource
{
    /**
     * @param  object  $folder  Webklex\PHPIMAP\Folder
     * @param  array{bounce: string, complaint: string, reply: string, auto_reply: string, unknown: string}  $afterProcess
     * @param  array{bounce: string, complaint: string, reply: string}  $folders
     */
    public function __construct(
        private readonly string $inboxKey,
        private readonly object $folder,
        private readonly array $afterProcess,
        private readonly array $folders,
        private readonly ?int $sinceDays = null,
    ) {}

    public function inboxKey(): string
    {
        return $this->inboxKey;
    }

    public function fetch(int $limit): iterable
    {
        $query = $this->folder->messages()->unseen();
        if ($this->sinceDays !== null && $this->sinceDays > 0) {
            $query = $query->since(now()->subDays($this->sinceDays));
        }

        $messages = $query->limit($limit)->get();

        foreach ($messages as $message) {
            yield [
                'uid' => (string) $message->getUid(),
                'raw' => $this->rawSource($message),
                '__source' => $message,
            ];
        }
    }

    /**
     * Webklex splits a fetched message into header and body — getRawBody() is
     * the body ONLY. Everything downstream (header parsing, matching, the .eml
     * in the forward) needs the full RFC 822 source, so recompose it from the
     * raw header block plus the raw body.
     */
    private function rawSource(object $message): string
    {
        $rawBody = (string) $message->getRawBody();

        $header = method_exists($message, 'getHeader') ? $message->getHeader() : null;
        $rawHeader = is_object($header) && property_exists($header, 'raw') ? rtrim((string) $header->raw, "\r\n") : '';

        return $rawHeader === '' ? $rawBody : $rawHeader."\r\n\r\n".$rawBody;
    }

    public function markProcessed(string $uid, BounceClassification $classification): void
    {
        $category = match ($classification) {
            BounceClassification::HardBounce, BounceClassification::SoftBounce => 'bounce',
            BounceClassification::Complaint => 'complaint',
            BounceClassification::Reply => 'reply',
            BounceClassification::AutoReply => 'auto_reply',
            BounceClassification::Unknown => 'unknown',
        };

        $action = $this->afterProcess[$category];

        try {
            $message = $this->findMessageByUid($uid);
            if ($message === null) {
                return;
            }

            match ($action) {
                'move' => $this->moveMessage($message, $category),
                'delete' => $message->delete(),
                'seen' => $message->setFlag('Seen'),
                'noop' => null,
                default => $message->setFlag('Seen'),
            };
        } catch (Throwable $e) {
            // Failure to flag is not fatal — idempotency table prevents reprocessing.
            Log::warning('WebklexInboundMessageSource: markProcessed failed', [
                'inbox_key' => $this->inboxKey,
                'imap_uid' => $uid,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function findMessageByUid(string $uid): ?object
    {
        $msg = $this->folder->messages()->getMessageByUid($uid);

        return is_object($msg) ? $msg : null;
    }

    private function moveMessage(object $message, string $category): void
    {
        $target = $this->folders[$category] ?? null;
        if (! is_string($target) || $target === '') {
            $message->setFlag('Seen');

            return;
        }
        $message->move($target);
    }
}
