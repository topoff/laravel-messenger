<?php

declare(strict_types=1);

namespace Topoff\Messenger\Services\Sms;

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
            }

            $message->tracking_message_id = $providerMessageId;
            $message->tracking_recipient_contact = $recipient;
            $message->tracking_meta = array_merge($trackingMeta, $meta);
            $message->save();
        });
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
     */
    public function recordFinal(Message $message, string $provider, string $status, bool $delivered, ?Carbon $at, array $meta): void
    {
        $decision = $this->locked($message->getKey(), function (Message $locked) use ($provider, $status, $delivered, $at, $meta): string {
            $trackingMeta = $locked->tracking_meta ?: [];

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
