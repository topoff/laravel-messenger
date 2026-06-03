<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * Aggregate counts from one ImapBounceProcessor run, returned to the caller
 * (typically the queued ProcessImapInboxJob or the fetch-bounces command).
 */
final class ProcessingResult
{
    public int $hardBounces = 0;

    public int $softBounces = 0;

    public int $complaints = 0;

    public int $replies = 0;

    public int $autoReplies = 0;

    public int $unknown = 0;

    public int $skippedDuplicate = 0;

    public int $errors = 0;

    public function __construct(public readonly string $inboxKey) {}

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'inbox_key' => $this->inboxKey,
            'hard_bounces' => $this->hardBounces,
            'soft_bounces' => $this->softBounces,
            'complaints' => $this->complaints,
            'replies' => $this->replies,
            'auto_replies' => $this->autoReplies,
            'unknown' => $this->unknown,
            'skipped_duplicate' => $this->skippedDuplicate,
            'errors' => $this->errors,
        ];
    }
}
