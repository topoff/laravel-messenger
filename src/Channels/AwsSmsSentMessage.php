<?php

declare(strict_types=1);

namespace Topoff\Messenger\Channels;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * Response of the `aws-sms` channel. Traversable with one item exposing
 * getMessageId()/getTo() — the same shape RecordNotificationSentListener
 * reads from the Vonage response.
 *
 * @implements IteratorAggregate<int, AwsSmsSentMessage>
 */
readonly class AwsSmsSentMessage implements IteratorAggregate
{
    public function __construct(private string $messageId, private string $to) {}

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getTo(): string
    {
        return $this->to;
    }

    /**
     * @return Traversable<int, AwsSmsSentMessage>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator([$this]);
    }
}
