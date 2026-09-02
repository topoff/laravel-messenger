<?php

declare(strict_types=1);

use Topoff\Messenger\Services\Imap\WebklexInboundMessageSource;

/**
 * The webklex API splits a message into header and body: getRawBody() is the
 * body ONLY. These tests pin down that fetch() recomposes the full RFC 822
 * source — losing the headers breaks matching, classification and the .eml
 * attached to forwards (production incident 2026-09-01).
 */
function fakeWebklexMessage(string $rawHeader, string $rawBody, int $uid = 1): object
{
    $header = new class
    {
        public string $raw = '';
    };
    $header->raw = $rawHeader;

    return new readonly class($header, $rawBody, $uid)
    {
        public function __construct(private object $header, private string $rawBody, private int $uid) {}

        public function getUid(): int
        {
            return $this->uid;
        }

        public function getRawBody(): string
        {
            return $this->rawBody;
        }

        public function getHeader(): object
        {
            return $this->header;
        }
    };
}

function fakeWebklexFolder(array $messages): object
{
    $query = new readonly class($messages)
    {
        public function __construct(private array $messages) {}

        public function unseen(): static
        {
            return $this;
        }

        public function since(mixed $date): static
        {
            return $this;
        }

        public function limit(int $limit): static
        {
            return $this;
        }

        public function get(): array
        {
            return $this->messages;
        }
    };

    return new readonly class($query)
    {
        public function __construct(private object $query) {}

        public function messages(): object
        {
            return $this->query;
        }
    };
}

it('recomposes the full raw source from header block and body', function () {
    $rawHeader = "From: Andi <andi@example.com>\r\nTo: no-reply@top-offerten.ch\r\nSubject: Test\r\nDate: Tue, 1 Sep 2026 21:00:00 +0000";
    $rawBody = "Test von Andi\r\n";

    $source = new WebklexInboundMessageSource(
        inboxKey: 'noreply',
        folder: fakeWebklexFolder([fakeWebklexMessage($rawHeader, $rawBody, 42)]),
        afterProcess: ['bounce' => 'seen', 'complaint' => 'seen', 'reply' => 'seen', 'auto_reply' => 'seen', 'unknown' => 'seen'],
        folders: ['bounce' => '', 'complaint' => '', 'reply' => ''],
    );

    $items = iterator_to_array($source->fetch(10), preserve_keys: false);

    expect($items)->toHaveCount(1)
        ->and($items[0]['uid'])->toBe('42')
        ->and($items[0]['raw'])->toBe($rawHeader."\r\n\r\n".$rawBody);
});

it('strips a trailing blank line from the header block before recomposing', function () {
    $source = new WebklexInboundMessageSource(
        inboxKey: 'noreply',
        folder: fakeWebklexFolder([fakeWebklexMessage("Subject: Hi\r\n\r\n", 'Body')]),
        afterProcess: ['bounce' => 'seen', 'complaint' => 'seen', 'reply' => 'seen', 'auto_reply' => 'seen', 'unknown' => 'seen'],
        folders: ['bounce' => '', 'complaint' => '', 'reply' => ''],
    );

    $items = iterator_to_array($source->fetch(10), preserve_keys: false);

    expect($items[0]['raw'])->toBe("Subject: Hi\r\n\r\nBody");
});

it('falls back to the body alone when no header block is available', function () {
    $message = new class
    {
        public function getUid(): int
        {
            return 7;
        }

        public function getRawBody(): string
        {
            return 'Body only';
        }

        public function getHeader(): ?object
        {
            return null;
        }
    };

    $source = new WebklexInboundMessageSource(
        inboxKey: 'noreply',
        folder: fakeWebklexFolder([$message]),
        afterProcess: ['bounce' => 'seen', 'complaint' => 'seen', 'reply' => 'seen', 'auto_reply' => 'seen', 'unknown' => 'seen'],
        folders: ['bounce' => '', 'complaint' => '', 'reply' => ''],
    );

    $items = iterator_to_array($source->fetch(10), preserve_keys: false);

    expect($items[0]['raw'])->toBe('Body only');
});
