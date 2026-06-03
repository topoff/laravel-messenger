<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * Abstraction over the source of inbound messages so the processor doesn't
 * depend directly on Webklex. Two implementations exist:
 *
 *   - WebklexInboundMessageSource (production): adapts a connected Webklex Folder
 *   - InMemoryInboundMessageSource (tests): yields fixture .eml strings
 *
 * The processor consumes uid + raw .eml from `fetch()`, then calls back into
 * `markProcessed()` after classification so the implementation can perform the
 * desired IMAP-side action (move / flag / delete / noop).
 */
interface InboundMessageSource
{
    /**
     * @return iterable<array{uid: string, raw: string}>
     */
    public function fetch(int $limit): iterable;

    public function markProcessed(string $uid, BounceClassification $classification): void;

    public function inboxKey(): string;
}
