<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * One flattened MIME part of an inbound email. Sub-headers (Content-Type,
 * Content-Disposition, …) are kept on the part. Body is already decoded
 * (quoted-printable / base64 unwrapped) but kept as raw bytes.
 *
 * @phpstan-type HeaderMap array<string, list<string>>
 */
final readonly class InboundMimePart
{
    /**
     * @param  HeaderMap  $headers  lowercased header name → list of raw values
     * @param  array<string, string>  $contentTypeParams  e.g. ['boundary' => 'xyz', 'charset' => 'utf-8']
     */
    public function __construct(
        public array $headers,
        public string $contentType,
        public array $contentTypeParams,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[mb_strtolower($name)] ?? [];

        return $values[0] ?? null;
    }
}
