<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

/**
 * Minimal RFC 822 / RFC 2045 parser tailored to inbound bounce/complaint
 * processing. Not a full MIME implementation — we only need:
 *
 *   • header parsing with folding (RFC 5322 §2.2.3)
 *   • flat enumeration of multipart parts (recursive walk, flattened output)
 *   • Content-Transfer-Encoding decoding (quoted-printable, base64, 7bit/8bit)
 *
 * Returns an InboundMessage whose `parts` list is flattened across all
 * multipart nesting levels. For our DSN/ARF use case this is enough.
 */
final class InboundMessageParser
{
    public function parse(string $raw): InboundMessage
    {
        $raw = $this->normalizeLineEndings($raw);
        [$rawHeaders, $body] = $this->splitHeadersAndBody($raw);
        $headers = $this->parseHeaders($rawHeaders);

        [$contentType, $params] = $this->parseContentType($this->firstHeader($headers, 'content-type') ?? 'text/plain');
        $rootPart = new InboundMimePart(
            headers: $headers,
            contentType: $contentType,
            contentTypeParams: $params,
            body: $body,
        );

        $parts = $this->expandPart($rootPart);

        return new InboundMessage(headers: $headers, parts: $parts, raw: $raw);
    }

    private function normalizeLineEndings(string $raw): string
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = str_replace("\r", "\n", $raw);

        return str_replace("\n", "\r\n", $raw);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitHeadersAndBody(string $raw): array
    {
        $boundary = "\r\n\r\n";
        $pos = strpos($raw, $boundary);
        if ($pos === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $pos), substr($raw, $pos + strlen($boundary))];
    }

    /**
     * @return array<string, list<string>>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        if ($rawHeaders === '') {
            return [];
        }

        $unfolded = preg_replace("/\r\n[ \t]+/", ' ', $rawHeaders) ?? $rawHeaders;
        $lines = explode("\r\n", $unfolded);
        $headers = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = mb_strtolower(trim(substr($line, 0, $colon)));
            $value = ltrim(substr($line, $colon + 1));
            $headers[$name] ??= [];
            $headers[$name][] = $value;
        }

        return $headers;
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private function firstHeader(array $headers, string $name): ?string
    {
        return $headers[mb_strtolower($name)][0] ?? null;
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function parseContentType(string $rawValue): array
    {
        $segments = $this->splitHeaderParams($rawValue);
        $mediaType = mb_strtolower(array_shift($segments) ?? 'text/plain');
        $params = [];
        foreach ($segments as $segment) {
            if (! str_contains($segment, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $segment, 2);
            $params[mb_strtolower(trim($k))] = trim($v, " \t\"");
        }

        return [$mediaType, $params];
    }

    /**
     * Split a header value on ';' while respecting quoted strings.
     *
     * @return list<string>
     */
    private function splitHeaderParams(string $value): array
    {
        $segments = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($char === '"') {
                $inQuotes = ! $inQuotes;
                $current .= $char;

                continue;
            }
            if ($char === ';' && ! $inQuotes) {
                $segments[] = trim($current);
                $current = '';

                continue;
            }
            $current .= $char;
        }
        if (trim($current) !== '') {
            $segments[] = trim($current);
        }

        return $segments;
    }

    /**
     * @return list<InboundMimePart>
     */
    private function expandPart(InboundMimePart $part): array
    {
        if (! str_starts_with($part->contentType, 'multipart/')) {
            return [$this->withDecodedBody($part)];
        }

        $boundary = $part->contentTypeParams['boundary'] ?? null;
        if (! is_string($boundary) || $boundary === '') {
            return [$this->withDecodedBody($part)];
        }

        $result = [];
        foreach ($this->splitOnBoundary($part->body, $boundary) as $childRaw) {
            $childRaw = ltrim($childRaw, "\r\n");
            [$childHeadersRaw, $childBody] = $this->splitHeadersAndBody($childRaw);
            $childHeaders = $this->parseHeaders($childHeadersRaw);
            [$childContentType, $childParams] = $this->parseContentType(
                $this->firstHeader($childHeaders, 'content-type') ?? 'text/plain'
            );

            $childPart = new InboundMimePart(
                headers: $childHeaders,
                contentType: $childContentType,
                contentTypeParams: $childParams,
                body: $childBody,
            );

            // Recurse into nested multiparts; flatten everything into one list.
            foreach ($this->expandPart($childPart) as $flat) {
                $result[] = $flat;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function splitOnBoundary(string $body, string $boundary): array
    {
        $marker = '--'.$boundary;

        $parts = [];
        $cursor = 0;
        $length = strlen($body);
        while ($cursor < $length) {
            $startPos = strpos($body, $marker, $cursor);
            if ($startPos === false) {
                break;
            }
            $afterStart = $startPos + strlen($marker);
            // End-of-multipart marker — stop after this.
            $isEnd = substr($body, $afterStart, 2) === '--';
            if ($isEnd) {
                break;
            }
            $afterStart = $this->skipPastEol($body, $afterStart);
            $nextStart = strpos($body, $marker, $afterStart);
            if ($nextStart === false) {
                // Trailing chunk with no closing boundary; take rest.
                $parts[] = substr($body, $afterStart);
                break;
            }
            // Strip trailing CRLF before next boundary.
            $end = $nextStart;
            if ($end >= 2 && substr($body, $end - 2, 2) === "\r\n") {
                $end -= 2;
            }
            $parts[] = substr($body, $afterStart, $end - $afterStart);
            $cursor = $nextStart;

            // Stop if next is end marker.
            if (substr($body, $nextStart + strlen($marker), 2) === '--') {
                break;
            }
        }

        return $parts;
    }

    private function skipPastEol(string $body, int $pos): int
    {
        if (substr($body, $pos, 2) === "\r\n") {
            return $pos + 2;
        }
        if (substr($body, $pos, 1) === "\n") {
            return $pos + 1;
        }

        return $pos;
    }

    private function withDecodedBody(InboundMimePart $part): InboundMimePart
    {
        $encoding = mb_strtolower(trim((string) $part->header('content-transfer-encoding')));
        $body = match ($encoding) {
            'quoted-printable' => quoted_printable_decode($part->body),
            'base64' => base64_decode($part->body, strict: false),
            default => $part->body,
        };

        if ($body === $part->body) {
            return $part;
        }

        return new InboundMimePart(
            headers: $part->headers,
            contentType: $part->contentType,
            contentTypeParams: $part->contentTypeParams,
            body: $body,
        );
    }
}
