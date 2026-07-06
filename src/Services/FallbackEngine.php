<?php

namespace Topoff\Messenger\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Topoff\Messenger\Events\MessageFallbackCreatedEvent;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Models\MessageType;
use Topoff\Messenger\Repositories\MessageTypeRepository;

/**
 * Status-driven fallback engine (v9, E79): when a sent message of a type
 * with a configured fallback has no delivery confirmation after the
 * type's timeout — or bounced — a follow-up message of the fallback type
 * (usually another channel) is created with the same receiver, context,
 * params and locale.
 *
 * Guards:
 *  - one follow-up per message (idempotent runs);
 *  - loop protection: the fallback chain never revisits a message type
 *    and is capped at MAX_CHAIN_LENGTH;
 *  - marketing fallbacks respect the consent guard;
 *  - only configure fallbacks on types whose channel reports delivery
 *    confirmations (SES / Vonage DLR) — a channel that never sets
 *    `delivered_at` would otherwise always trigger the fallback.
 */
class FallbackEngine
{
    protected const int MAX_CHAIN_LENGTH = 3;

    public function __construct(
        protected ConsentService $consent,
        protected MessageTypeRepository $messageTypes,
    ) {}

    /**
     * @return int number of follow-up messages created
     */
    public function run(): int
    {
        $created = 0;
        $messageClass = config('messenger.models.message');

        $candidateTypes = MessageType::whereNotNull('fallback_message_type_id')->get();

        foreach ($candidateTypes as $type) {
            $timeout = Carbon::now()->subMinutes((int) ($type->fallback_after_minutes ?? 60));

            $due = $messageClass::where('message_type_id', $type->id)
                ->whereNotNull('sent_at')
                ->whereNull('delivered_at')
                ->where(function ($query) use ($timeout): void {
                    $query->where('sent_at', '<=', $timeout)
                        ->orWhereNotNull('bounced_at');
                })
                ->whereNotExists(function ($query) use ($messageClass): void {
                    $query->selectRaw('1')
                        ->from((new $messageClass)->getTable().' as followups')
                        ->whereColumn('followups.fallback_of_message_id', 'messages.id');
                })
                ->get();

            foreach ($due as $message) {
                if ($this->createFallback($message, $type)) {
                    $created++;
                }
            }
        }

        return $created;
    }

    protected function createFallback(Message $message, MessageType $type): bool
    {
        $fallbackType = MessageType::find($type->fallback_message_type_id);

        if (! $fallbackType) {
            return false;
        }

        if ($this->chainForbids($message, $fallbackType)) {
            return false;
        }

        if ($message->receiver_type !== null && $message->receiver_id !== null
            && $this->consent->isOptedOut($message->receiver_type, $message->receiver_id, $fallbackType)) {
            return false;
        }

        $messageClass = config('messenger.models.message');
        $followUp = $messageClass::create([
            'sender_type' => $message->sender_type,
            'sender_id' => $message->sender_id,
            'receiver_type' => $message->receiver_type,
            'receiver_id' => $message->receiver_id,
            'company_id' => $message->company_id,
            'message_type_id' => $fallbackType->id,
            'messagable_type' => $message->messagable_type,
            'messagable_id' => $message->messagable_id,
            'params' => $message->params,
            'locale' => $message->locale,
            'scheduled_at' => null,
            'fallback_of_message_id' => $message->id,
        ]);

        Event::dispatch(new MessageFallbackCreatedEvent($followUp, $message));

        return true;
    }

    /**
     * Loop protection: never revisit a type within one chain and cap the
     * chain length.
     */
    protected function chainForbids(Message $message, MessageType $fallbackType): bool
    {
        $messageClass = config('messenger.models.message');
        $typeIdsInChain = [$message->message_type_id];
        $current = $message;
        $length = 1;

        while ($current->fallback_of_message_id !== null && $length <= self::MAX_CHAIN_LENGTH) {
            $current = $messageClass::find($current->fallback_of_message_id);

            if (! $current) {
                break;
            }

            $typeIdsInChain[] = $current->message_type_id;
            $length++;
        }

        return $length >= self::MAX_CHAIN_LENGTH || in_array($fallbackType->id, $typeIdsInChain, true);
    }
}
