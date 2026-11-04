<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Sms;

use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;
use Topoff\Messenger\Events\MessageDeliveredEvent;
use Topoff\Messenger\Events\MessageRejectedEvent;
use Topoff\Messenger\Events\SmsDeliveryFailedEvent;
use Topoff\Messenger\Models\Message;

/**
 * Shared bookkeeping for SMS provider ids and final delivery states (AWS
 * events, Vonage DLRs, the send listener):
 *
 * - every read-modify-write of a message row runs under a row lock, so the
 *   send listener and an early delivery event never overwrite each other's
 *   tracking_meta keys;
 * - the first final state wins (delivered_at XOR failed_at); later
 *   contradicting finals are only kept in tracking_meta.late_events;
 * - the business event is fired at least once: the final UPDATE stores
 *   tracking_meta.sms_final_event_pending, which is cleared only after all
 *   events were dispatched. A crash in between is picked up by a retry of the
 *   same delivery event or by messenger:dispatch-pending-sms-events.
 */
class SmsDeliveryRecorder
{
    public const string PENDING_KEY = 'sms_final_event_pending';

    public const int CLAIM_TTL_MINUTES = 5;

    /** Provider ids of all parts of the current multipart send (only with the messenger's client reference). */
    public const string PART_IDS_KEY = 'sms_part_ids';

    /** Part ids of earlier sends of the same message. */
    public const string PREVIOUS_PART_IDS_KEY = 'previous_sms_part_ids';

    /** Last reported status per part id of the current send. */
    public const string PART_STATUSES_KEY = 'sms_part_statuses';

    /** Part statuses a later intermediate receipt must not replace. */
    private const array FINAL_PART_STATUSES = ['delivered', 'failed', 'rejected', 'expired'];

    public const string SEND_CURRENT = 'current';

    public const string SEND_PREVIOUS = 'previous';

    public const string SEND_UNKNOWN = 'unknown';

    /**
     * Find the message an SMS provider event belongs to: by the messenger id
     * echoed back by the provider first, then by the current or a previous
     * provider message id.
     */
    public function findMessage(int|string|null $messengerMessageId, ?string $providerMessageId): ?Message
    {
        $messageClass = config('messenger.models.message');

        if ($messengerMessageId !== null && $messengerMessageId !== '') {
            $message = $messageClass::query()->whereKey($messengerMessageId)->first();
            if ($message) {
                return $message;
            }
        }

        if ($providerMessageId === null || $providerMessageId === '') {
            return null;
        }

        $message = $messageClass::query()->where('tracking_message_id', $providerMessageId)->first();
        if ($message || preg_match('/^[A-Za-z0-9-]+$/', $providerMessageId) !== 1) {
            return $message;
        }

        // A second successful send moved the older id into previous_message_ids.
        return $messageClass::query()
            ->where('tracking_meta', 'like', '%"previous_message_ids":%')
            ->where('tracking_meta', 'like', '%"'.$providerMessageId.'"%')
            ->get()
            ->first(fn (Message $candidate): bool => in_array($providerMessageId, (array) data_get($candidate->tracking_meta, 'previous_message_ids', []), true));
    }

    /**
     * Store the provider id of a successful send. An already stored id of an
     * earlier send of the same message is kept in previous_message_ids.
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordSent(int|string $messageId, string $providerMessageId, ?string $recipient, array $meta): void
    {
        $this->locked($messageId, function (Message $message) use ($providerMessageId, $recipient, $meta): void {
            $trackingMeta = $message->tracking_meta ?: [];

            $previousId = $message->tracking_message_id;
            if (is_string($previousId) && $previousId !== '' && $previousId !== $providerMessageId) {
                $trackingMeta['previous_message_ids'] = array_values(array_unique([...(array) ($trackingMeta['previous_message_ids'] ?? []), $previousId]));

                // A new send: part ids of the earlier send only identify its late receipts.
                $earlierPartIds = (array) ($trackingMeta[self::PART_IDS_KEY] ?? []);
                if ($earlierPartIds !== []) {
                    $trackingMeta[self::PREVIOUS_PART_IDS_KEY] = array_values(array_unique([...(array) ($trackingMeta[self::PREVIOUS_PART_IDS_KEY] ?? []), ...$earlierPartIds]));
                }
            }

            if ($previousId !== $providerMessageId) {
                unset($trackingMeta[self::PART_IDS_KEY], $trackingMeta[self::PART_STATUSES_KEY]);
            }

            $message->tracking_message_id = $providerMessageId;
            $message->tracking_recipient_contact = $recipient;
            $message->tracking_meta = array_merge($trackingMeta, $meta);
            $message->save();
        });
    }

    /**
     * Which send of the message a provider id belongs to: the current one
     * (stored id or one of its parts), an earlier one, or none known yet (the
     * receipt overtook recordSent()).
     */
    public function sendOf(Message $message, string $providerMessageId): string
    {
        $trackingMeta = $message->tracking_meta ?: [];

        if ($message->tracking_message_id === $providerMessageId || in_array($providerMessageId, (array) ($trackingMeta[self::PART_IDS_KEY] ?? []), true)) {
            return self::SEND_CURRENT;
        }

        if (in_array($providerMessageId, (array) ($trackingMeta['previous_message_ids'] ?? []), true)
            || in_array($providerMessageId, (array) ($trackingMeta[self::PREVIOUS_PART_IDS_KEY] ?? []), true)) {
            return self::SEND_PREVIOUS;
        }

        return self::SEND_UNKNOWN;
    }

    /**
     * Store the status of one part of the current send and merge the receipt
     * details (also for a single-part send) — re-checked under the row lock, so a send recorded meanwhile is
     * never touched. Returns null when the id no longer belongs to the current
     * send, else whether every part has reported `delivered` (always true for
     * a single-part send). A final part status is never replaced by an
     * intermediate one (e.g. a late `accepted` after `delivered`).
     *
     * @param  array<string, mixed>  $meta
     */
    public function recordPartStatus(int|string $messageId, string $providerMessageId, string $status, array $meta): ?bool
    {
        return $this->locked($messageId, function (Message $message) use ($providerMessageId, $status, $meta): ?bool {
            if ($this->sendOf($message, $providerMessageId) !== self::SEND_CURRENT) {
                return null;
            }

            $trackingMeta = $message->tracking_meta ?: [];
            if ((array) ($trackingMeta[self::PART_IDS_KEY] ?? []) === []) {
                $message->tracking_meta = array_merge($trackingMeta, $meta);
                $message->save();

                return true;
            }

            $statuses = (array) ($trackingMeta[self::PART_STATUSES_KEY] ?? []);
            $known = $statuses[$providerMessageId] ?? null;
            if (! in_array($known, self::FINAL_PART_STATUSES, true) || in_array($status, self::FINAL_PART_STATUSES, true)) {
                $statuses[$providerMessageId] = $status;
            }

            $trackingMeta = array_merge($trackingMeta, $meta);
            $trackingMeta[self::PART_STATUSES_KEY] = $statuses;
            $message->tracking_meta = $trackingMeta;
            $message->save();

            return $this->allPartsDelivered($message);
        });
    }

    /**
     * Whether every part of the current send reported `delivered` (true for a
     * single-part send).
     */
    public function allPartsDelivered(Message $message): bool
    {
        $trackingMeta = $message->tracking_meta ?: [];
        $statuses = (array) ($trackingMeta[self::PART_STATUSES_KEY] ?? []);

        return array_all((array) ($trackingMeta[self::PART_IDS_KEY] ?? []), fn (mixed $partId): bool => ($statuses[$partId] ?? null) === 'delivered');
    }

    /**
     * Keep a receipt of an earlier send, which must not decide the message's
     * final state, in tracking_meta.late_events (once per id and status).
     */
    public function recordLateEvent(int|string $messageId, string $provider, string $status, string $providerMessageId): void
    {
        $this->locked($messageId, function (Message $message) use ($provider, $status, $providerMessageId): void {
            $trackingMeta = $message->tracking_meta ?: [];
            if ($this->appendLateEvent($trackingMeta, $provider, $status, $providerMessageId)) {
                $message->tracking_meta = $trackingMeta;
                $message->save();
            }
        });
    }

    /**
     * @param  array<string, mixed>  $trackingMeta
     * @return bool whether the event was appended (false: already recorded)
     */
    private function appendLateEvent(array &$trackingMeta, string $provider, string $status, string $providerMessageId): bool
    {
        $lateEvents = (array) ($trackingMeta['late_events'] ?? []);
        foreach ($lateEvents as $lateEvent) {
            if (data_get($lateEvent, 'provider_message_id') === $providerMessageId && data_get($lateEvent, 'status') === $status) {
                return false;
            }
        }

        $lateEvents[] = [
            'provider' => $provider,
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'received_at' => now()->toIso8601String(),
        ];
        $trackingMeta['late_events'] = $lateEvents;

        return true;
    }

    /**
     * Merge non-final status details into tracking_meta (e.g. Vonage DLR fields).
     *
     * @param  array<string, mixed>  $meta
     */
    public function mergeMeta(int|string $messageId, array $meta): void
    {
        $this->locked($messageId, function (Message $message) use ($meta): void {
            $message->tracking_meta = array_merge($message->tracking_meta ?: [], $meta);
            $message->save();
        });
    }

    /**
     * Record a final delivery state and fire the business event(s).
     *
     * @param  array<string, mixed>  $meta
     * @param  (Closure(Message): bool)|null  $stillApplies  checked under the row lock; false keeps
     *                                                       the receipt only as late event
     */
    public function recordFinal(Message $message, string $provider, string $status, bool $delivered, ?Carbon $at, array $meta, ?Closure $stillApplies = null, ?string $providerMessageId = null): void
    {
        $decision = $this->locked($message->getKey(), function (Message $locked) use ($provider, $status, $delivered, $at, $meta, $stillApplies, $providerMessageId): string {
            $trackingMeta = $locked->tracking_meta ?: [];

            // Re-checked under the lock: a send recorded since the receipt was
            // classified makes it a late receipt of the earlier send.
            if ($stillApplies instanceof Closure && ! $stillApplies($locked)) {
                if ($this->appendLateEvent($trackingMeta, $provider, $status, $providerMessageId ?? '')) {
                    $locked->tracking_meta = $trackingMeta;
                    $locked->save();
                }

                return 'done';
            }

            if ($locked->delivered_at === null && $locked->failed_at === null) {
                if ($delivered) {
                    $locked->delivered_at = $at ?? now();
                } else {
                    $locked->failed_at = $at ?? now();
                }

                $trackingMeta = array_merge($trackingMeta, $meta);
                $trackingMeta[self::PENDING_KEY] = ['provider' => $provider, 'status' => $status, 'delivered' => $delivered];
                $locked->tracking_meta = $trackingMeta;
                $locked->save();

                return 'dispatch';
            }

            $pending = $trackingMeta[self::PENDING_KEY] ?? null;
            if (is_array($pending) && ($pending['provider'] ?? null) === $provider && ($pending['status'] ?? null) === $status) {
                return 'dispatch';
            }

            $alreadyRecorded = ($trackingMeta['sms_final_status'] ?? null) === $status && ($trackingMeta['sms_final_provider'] ?? null) === $provider;
            if (! $alreadyRecorded) {
                $trackingMeta['late_events'] = [...(array) ($trackingMeta['late_events'] ?? []), [
                    'provider' => $provider,
                    'status' => $status,
                    'received_at' => now()->toIso8601String(),
                ]];
                $locked->tracking_meta = $trackingMeta;
                $locked->save();
            }

            return 'done';
        });

        if ($decision === 'dispatch') {
            $this->dispatchPending($message->getKey());
        }
    }

    /**
     * Fire the business event(s) of a pending final state and clear the marker.
     * Throws (after trying every event) when a listener failed, so the caller's
     * retry fires them again — at-least-once.
     *
     * Concurrent callers (two copies of the same delivery event, job and sweep)
     * claim the marker under the row lock first; only the claimant dispatches. A
     * claim older than CLAIM_TTL_MINUTES counts as abandoned (worker died) and
     * may be taken over.
     *
     * @return bool whether this call dispatched the pending event(s)
     */
    public function dispatchPending(int|string $messageId): bool
    {
        $pending = $this->locked($messageId, function (Message $locked): ?array {
            $trackingMeta = $locked->tracking_meta ?: [];
            $pending = $trackingMeta[self::PENDING_KEY] ?? null;
            if (! is_array($pending)) {
                return null;
            }

            $claimedAt = isset($pending['claimed_at']) ? Carbon::parse((string) $pending['claimed_at']) : null;
            if ($claimedAt instanceof Carbon && $claimedAt->greaterThan(now()->subMinutes(self::CLAIM_TTL_MINUTES))) {
                return null;
            }

            $pending['claimed_at'] = now()->toIso8601String();
            $trackingMeta[self::PENDING_KEY] = $pending;
            $locked->tracking_meta = $trackingMeta;
            $locked->save();

            return $pending;
        });

        if (! is_array($pending)) {
            return false;
        }

        $messageClass = config('messenger.models.message');
        /** @var Message $message */
        $message = $messageClass::query()->whereKey($messageId)->firstOrFail();

        $events = (bool) ($pending['delivered'] ?? false)
            ? [new MessageDeliveredEvent($message)]
            : array_values(array_filter([
                // Vonage hosts have always received MessageRejectedEvent for failed SMS; keep it first.
                ($pending['provider'] ?? null) === 'vonage' ? new MessageRejectedEvent((string) $pending['status'], $message) : null,
                new SmsDeliveryFailedEvent((string) $pending['status'], $message, (string) $pending['provider']),
            ]));

        $failures = [];
        foreach ($events as $event) {
            try {
                Event::dispatch($event);
            } catch (Throwable $t) {
                report($t);
                $failures[] = $t;
            }
        }

        if ($failures !== []) {
            // Release the claim so the caller's retry can dispatch again right away.
            $this->locked($messageId, function (Message $locked) use ($pending): void {
                $trackingMeta = $locked->tracking_meta ?: [];
                if (($trackingMeta[self::PENDING_KEY] ?? null) == $pending) {
                    unset($trackingMeta[self::PENDING_KEY]['claimed_at']);
                    $locked->tracking_meta = $trackingMeta;
                    $locked->save();
                }
            });

            throw $failures[0];
        }

        $this->locked($messageId, function (Message $locked) use ($pending): void {
            $trackingMeta = $locked->tracking_meta ?: [];
            if (($trackingMeta[self::PENDING_KEY] ?? null) != $pending) {
                return;
            }

            unset($trackingMeta[self::PENDING_KEY]);
            $trackingMeta['sms_final_provider'] = $pending['provider'] ?? null;
            $trackingMeta['sms_final_status'] = $pending['status'] ?? null;
            $locked->tracking_meta = $trackingMeta;
            $locked->save();
        });

        return true;
    }

    /**
     * @template T
     *
     * @param  callable(Message): T  $callback
     * @return T|null
     */
    private function locked(int|string $messageId, callable $callback): mixed
    {
        $messageClass = config('messenger.models.message');
        $connection = new $messageClass()->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($messageClass, $messageId, $callback): mixed {
            /** @var Message|null $message */
            $message = $messageClass::query()->whereKey($messageId)->lockForUpdate()->first();

            return $message ? $callback($message) : null;
        });
    }
}
