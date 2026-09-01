<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Imap;

use Illuminate\Database\Eloquent\Collection;
use Topoff\Messenger\Models\Message;

/**
 * Result of a MessageMatcher lookup: the matched Messages plus WHICH path
 * produced the match. Consumers that act on a match (like routing a reply into
 * a customer-facing chat) need the source to judge its trustworthiness — the
 * id-based paths reference our unguessable stamped ids, while the
 * recipient-window fallback only proves the sender knew an address we mailed.
 */
final readonly class MatchOutcome
{
    public const string VIA_CORRELATION_ID = 'correlation_id';

    public const string VIA_SES_MESSAGE_ID = 'ses_message_id';

    public const string VIA_RECIPIENT_FALLBACK = 'recipient_fallback';

    /**
     * @param  Collection<int, Message>  $matches
     * @param  self::VIA_*|null  $via  null when nothing matched
     */
    public function __construct(
        public Collection $matches,
        public ?string $via,
    ) {}

    public function isIdBased(): bool
    {
        return in_array($this->via, [self::VIA_CORRELATION_ID, self::VIA_SES_MESSAGE_ID], true);
    }
}
