<?php

namespace Topoff\Messenger\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;
use Topoff\Messenger\MailHandler\MainBulkMailHandler;
use Topoff\Messenger\MailHandler\MainMailHandler;
use Topoff\Messenger\Models\Message;

class SendMessageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $chunkSize = 250;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Release the unique lock after this many seconds, even if the job is still running.
     * Prevents permanent lock when the worker is killed by timeout.
     */
    public int $uniqueFor = 55;

    /**
     * Create a new job instance.
     */
    public function __construct(
        /**
         * Only retry Messages with Error on this call
         */
        protected bool $isRetryCallForMessagesWithError = false
    ) {}

    /**
     * Differentiate normal send vs retry so both can be on the queue simultaneously.
     */
    public function uniqueId(): string
    {
        return $this->isRetryCallForMessagesWithError ? 'retry' : 'send';
    }

    /**
     * Execute the job.
     *
     * @throws Throwable
     */
    public function handle(): void
    {
        if ($this->isRetryCallForMessagesWithError) {
            $this->retryDirectMessages();
        } else {
            $this->sendDirectMessages();
            $this->sendIndirectMessages();
        }
    }

    protected function messageModel(): string
    {
        return config('messenger.models.message');
    }

    protected function messageTypeModel(): string
    {
        return config('messenger.models.message_type');
    }

    protected function messageTable(): string
    {
        $messageClass = $this->messageModel();

        return (new $messageClass)->getTable();
    }

    protected function messageTypeTable(): string
    {
        $messageTypeClass = $this->messageTypeModel();

        return (new $messageTypeClass)->getTable();
    }

    protected function throttleForStaging(): void
    {
        if (App::environment('staging')) {
            Sleep::sleep(1); // we can't send too many emails to mailtrap.io - 10 emails / 10 seconds
        }
    }

    protected function applyRetryWindowConstraint(Builder $query): Builder
    {
        $messageClass = $this->messageModel();
        $messageTable = $this->messageTable();
        $messageTypeTable = $this->messageTypeTable();
        $driver = (new $messageClass)->getConnection()->getDriverName();
        $now = Date::now()->toDateTimeString();

        return match ($driver) {
            'sqlite' => $query->whereRaw(
                "$messageTable.created_at > datetime(?, '-' || $messageTypeTable.error_stop_send_minutes || ' minutes')",
                [$now]
            ),
            'pgsql' => $query->whereRaw(
                "$messageTable.created_at > (?::timestamp - ($messageTypeTable.error_stop_send_minutes || ' minutes')::interval)",
                [$now]
            ),
            'sqlsrv' => $query->whereRaw(
                "$messageTable.created_at > DATEADD(minute, -$messageTypeTable.error_stop_send_minutes, ?)",
                [$now]
            ),
            default => $query->whereRaw(
                "$messageTable.created_at > DATE_SUB(?, INTERVAL $messageTypeTable.error_stop_send_minutes MINUTE)",
                [$now]
            ),
        };
    }

    /**
     * Call the MailHandler for a single message
     */
    protected function callMailHandlerWithSingleMessage(Message $message): void
    {
        $handlerClass = $message->messageType->single_handler;

        if (! $handlerClass || ! class_exists($handlerClass)) {
            Log::error('SendMessageJob: Invalid or missing single_handler for message.', [
                'message_id' => $message->id,
                'message_type_id' => $message->message_type_id,
                'single_handler' => $handlerClass,
            ]);
            $message->update(['error_at' => Date::now()]);

            return;
        }

        /** @var MainMailHandler $mailHandler or one of its child classes */
        $mailHandler = new $handlerClass($message);
        $mailHandler->send();
    }

    /**
     * Send all direct messages
     */
    protected function sendDirectMessages(): void
    {
        $messageClass = $this->messageModel();
        $messageClass::with('messageType')
            ->has('directMessageTypes')
            ->whereNull('sent_at')
            ->whereNull('failed_at')
            ->whereNull('reserved_at')
            ->whereNull('error_at')
            ->where(fn ($query) => $query->whereNull('scheduled_at')->orWhere('scheduled_at', '<', Date::now()))
            ->orderBy('id')
            ->chunkById($this->chunkSize, function (Collection $directMessages): void {
                foreach ($directMessages as $message) {
                    $this->callMailHandlerWithSingleMessage($message);
                    $this->throttleForStaging();
                }
            });
    }

    /**
     * Retry all direct messages, with were previously set to error or are
     * stuck in scheduled mode
     */
    protected function retryDirectMessages(): void
    {
        $messageClass = $this->messageModel();
        $messageTable = $this->messageTable();
        $messageTypeTable = $this->messageTypeTable();
        $driver = (new $messageClass)->getConnection()->getDriverName();
        $now = Date::now()->toDateTimeString();

        $query = $messageClass::with('messageType')
            ->select("$messageTable.*") // necessary because of join, otherwise it overwrites the id with the id from message_types
            ->join($messageTypeTable, "$messageTable.message_type_id", '=', "$messageTypeTable.id")
            ->has('directMessageTypes')
            ->whereNull("$messageTable.sent_at")
            ->whereNull("$messageTable.failed_at")
            ->whereRaw("$messageTable.attempts < $messageTypeTable.max_retry_attempts")
            ->where(fn ($query) => $query->whereNull("$messageTable.scheduled_at")->orWhere("$messageTable.scheduled_at", '<', Date::now()))
            ->where(fn ($query) => $query
                ->whereNull("$messageTable.reserved_at")
                ->orWhereRaw($this->buildBackoffWhereRaw("$messageTable.reserved_at", "$messageTable.attempts", $driver), [$now]))
            ->where(fn ($query) => $query
                ->whereNull("$messageTable.error_at")
                ->orWhereRaw($this->buildBackoffWhereRaw("$messageTable.error_at", "$messageTable.attempts", $driver), [$now]))
            ->orderBy("$messageTable.id");

        $this->applyRetryWindowConstraint($query)
            ->chunkById($this->chunkSize, function (Collection $directMessages): void {
                $directMessages->each(fn (Message $message) => $this->callMailHandlerWithSingleMessage($message));
            }, "$messageTable.id", 'id');
    }

    protected function buildIndirectMessagesQuery(bool $withMessageType = true): Builder
    {
        $messageClass = $this->messageModel();
        $messageTable = $this->messageTable();
        $messageTypeTable = $this->messageTypeTable();

        $query = $messageClass::query()
            ->select("$messageTable.*")
            ->leftJoin($messageTypeTable, "$messageTable.message_type_id", '=', "$messageTypeTable.id")
            ->where(fn (Builder $query) => $query->where("$messageTypeTable.direct", false)->orWhereNull("$messageTypeTable.direct"))
            ->whereNull("$messageTable.sent_at")
            ->whereNull("$messageTable.failed_at");

        if ($withMessageType) {
            $query->with('messageType');
        }

        if ($this->isRetryCallForMessagesWithError) {
            $driver = (new $messageClass)->getConnection()->getDriverName();
            $now = Date::now()->toDateTimeString();

            $query
                ->whereRaw("$messageTable.attempts < $messageTypeTable.max_retry_attempts")
                ->where(fn ($query) => $query->whereNull("$messageTable.scheduled_at")->orWhere("$messageTable.scheduled_at", '<', Date::now()))
                ->where(fn ($query) => $query
                    ->whereNull("$messageTable.reserved_at")
                    ->orWhereRaw($this->buildBackoffWhereRaw("$messageTable.reserved_at", "$messageTable.attempts", $driver), [$now]))
                ->where(fn ($query) => $query
                    ->whereNull("$messageTable.error_at")
                    ->orWhereRaw($this->buildBackoffWhereRaw("$messageTable.error_at", "$messageTable.attempts", $driver), [$now]));

            return $this->applyRetryWindowConstraint($query);
        }

        return $query
            ->whereNull("$messageTable.reserved_at")
            ->whereNull("$messageTable.error_at")
            ->where(fn ($query) => $query->whereNull("$messageTable.scheduled_at")->orWhere("$messageTable.scheduled_at", '<', Date::now()));
    }

    /**
     * Send all other messages, which or not of type direct
     * -> single & groupable|bulk messages
     */
    protected function sendIndirectMessages(): void
    {
        $messageTable = $this->messageTable();
        $messageTypeTable = $this->messageTypeTable();

        $groupsQuery = $this->buildIndirectMessagesQuery(false)
            ->select([
                "$messageTable.receiver_type",
                "$messageTable.receiver_id",
                "$messageTypeTable.bulk_handler",
            ])
            ->selectRaw('COUNT(*) as message_count')
            ->groupBy("$messageTable.receiver_type", "$messageTable.receiver_id", "$messageTypeTable.bulk_handler")
            ->orderBy("$messageTable.receiver_type")
            ->orderBy("$messageTable.receiver_id");

        foreach ($groupsQuery->cursor() as $group) {
            $messageGroupQuery = $this->buildIndirectMessagesQuery(true)
                ->where("$messageTable.receiver_type", $group->receiver_type)
                ->where("$messageTable.receiver_id", $group->receiver_id);

            if ($group->bulk_handler) {
                $messageGroupQuery->where("$messageTypeTable.bulk_handler", $group->bulk_handler);
            } else {
                $messageGroupQuery->whereNull("$messageTypeTable.bulk_handler");
            }

            $messageGroup = $messageGroupQuery->get();

            if ($messageGroup->isEmpty()) {
                Log::error('SendMessageJob: Message group query returned empty after grouping.', [
                    'receiver_type' => $group->receiver_type,
                    'receiver_id' => $group->receiver_id,
                ]);

                continue;
            }

            if ($group->bulk_handler && $group->receiver_id && (int) $group->message_count > 1) {
                // in case an account meanwhile is deleted
                if (! $messageGroup->first()->receiver) {
                    Log::error('SendMessageJob: Receiver deleted, removing messages from group.', [
                        'receiver_type' => $group->receiver_type,
                        'receiver_id' => $group->receiver_id,
                        'message_count' => $messageGroup->count(),
                    ]);
                    $messageGroup->each(fn (Message $message) => $message->delete());
                } else {
                    /** @var MainBulkMailHandler $bulkMailHandler */
                    $bulkMailHandler = $group->bulk_handler;
                    (new $bulkMailHandler($messageGroup->first()->receiver, $messageGroup))->send();
                }
            } else {
                $messageGroup->each(fn (Message $message) => $this->callMailHandlerWithSingleMessage($message));
            }

            $this->throttleForStaging();
        }
    }

    /**
     * Build database-specific SQL for exponential backoff check.
     *
     * Formula: min(2^(attempts-1) * 15, 960) minutes → 15m, 30m, 1h, 2h, 4h, 8h, 16h (capped)
     */
    protected function buildBackoffWhereRaw(string $column, string $attemptsColumn, string $driver): string
    {
        // Use CASE to avoid unsigned integer underflow when attempts=0 (MySQL computes 0-1 as unsigned before GREATEST can clamp)
        $safePower = "POWER(2, CASE WHEN $attemptsColumn > 0 THEN $attemptsColumn - 1 ELSE 0 END)";

        return match ($driver) {
            'sqlite' => "$column < datetime(?, '-' || MIN(CAST($safePower * 15 AS INTEGER), 960) || ' minutes')",
            'pgsql' => "$column < ?::timestamp - (LEAST($safePower * 15, 960) || ' minutes')::interval",
            default => "$column < DATE_SUB(?, INTERVAL LEAST($safePower * 15, 960) MINUTE)",
        };
    }
}
