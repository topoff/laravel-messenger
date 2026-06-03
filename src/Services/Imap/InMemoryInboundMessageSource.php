<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * Test double for InboundMessageSource. Constructed with a list of
 * (uid → raw .eml) pairs; records the markProcessed calls so tests can
 * assert on flag/move behavior without an IMAP server.
 */
class InMemoryInboundMessageSource implements InboundMessageSource
{
    /** @var list<array{uid: string, classification: BounceClassification}> */
    public array $marked = [];

    /**
     * @param  array<string, string>  $messages  uid → raw .eml
     */
    public function __construct(
        private readonly string $inboxKey,
        private readonly array $messages,
    ) {}

    public function inboxKey(): string
    {
        return $this->inboxKey;
    }

    public function fetch(int $limit): iterable
    {
        $count = 0;
        foreach ($this->messages as $uid => $raw) {
            if ($count >= $limit) {
                return;
            }
            yield ['uid' => (string) $uid, 'raw' => $raw];
            $count++;
        }
    }

    public function markProcessed(string $uid, BounceClassification $classification): void
    {
        $this->marked[] = ['uid' => $uid, 'classification' => $classification];
    }
}
