<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Carbon\CarbonImmutable;

/**
 * Result of classifying an inbound IMAP message.
 *
 * For bounces / complaints the relevant lookup fields (correlation id,
 * Message-ID, recipients) are filled. For genuine replies only the basic
 * envelope metadata is populated.
 */
final readonly class BounceReport
{
    /**
     * @param  list<string>  $recipients  failing recipients from DSN / ARF
     * @param  array<string, mixed>  $rawDsnFields  parsed RFC 3464 fields, for forensics
     */
    public function __construct(
        public BounceClassification $classification,
        public ?string $statusCode = null,
        public ?string $diagnosticCode = null,
        public array $recipients = [],
        public ?string $originalCorrelationId = null,
        public ?string $originalMessageId = null,
        public ?string $originalSesMessageId = null,
        public ?string $originalSubject = null,
        public ?CarbonImmutable $bouncedAt = null,
        public array $rawDsnFields = [],
        public ?string $subType = null,
    ) {}

    /**
     * Heuristic: true for SMTP-class 5.x.x status codes.
     */
    public function isPermanent(): bool
    {
        if ($this->classification === BounceClassification::HardBounce) {
            return true;
        }

        return is_string($this->statusCode) && str_starts_with($this->statusCode, '5.');
    }
}
