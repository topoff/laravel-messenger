<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * An inbound email (e.g. fetched from IMAP) decomposed into headers and a
 * flattened list of MIME parts. The flat structure is sufficient for our
 * bounce/complaint use case; deeper nesting hierarchy is not preserved.
 *
 * @phpstan-import-type HeaderMap from InboundMimePart
 */
final readonly class InboundMessage
{
    /**
     * @param  HeaderMap  $headers
     * @param  list<InboundMimePart>  $parts
     */
    public function __construct(
        public array $headers,
        public array $parts,
        public string $raw = '',
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[mb_strtolower($name)] ?? [];

        return $values[0] ?? null;
    }

    /**
     * @return list<string>
     */
    public function headersAll(string $name): array
    {
        return $this->headers[mb_strtolower($name)] ?? [];
    }

    public function subject(): string
    {
        return (string) $this->header('subject');
    }

    public function from(): string
    {
        return (string) $this->header('from');
    }

    /**
     * @return list<InboundMimePart>
     */
    public function partsByType(string $contentType): array
    {
        $needle = mb_strtolower($contentType);

        return array_values(array_filter(
            $this->parts,
            fn (InboundMimePart $p): bool => mb_strtolower($p->contentType) === $needle,
        ));
    }

    public function firstPartByType(string $contentType): ?InboundMimePart
    {
        return $this->partsByType($contentType)[0] ?? null;
    }

    public function hasPartType(string $contentType): bool
    {
        return $this->firstPartByType($contentType) instanceof InboundMimePart;
    }
}
