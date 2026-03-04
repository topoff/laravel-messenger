<?php

namespace Topoff\Messenger\Events;

class SesSnsWebhookReceivedEvent
{
    /**
     * @param  array<string, mixed>  $snsPayload
     * @param  array<string, mixed>  $sesMessage
     */
    public function __construct(
        public array $snsPayload,
        public array $sesMessage,
        public ?string $notificationType,
        public bool $processedSynchronously,
    ) {}
}
