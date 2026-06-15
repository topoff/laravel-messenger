<?php

declare(strict_types=1);

namespace Topoff\Messenger\Console;

use Illuminate\Console\Command;
use Throwable;
use Topoff\Messenger\Services\SesSns\SqsTrackingPoller;

class PollSqsTrackingCommand extends Command
{
    protected $signature = 'messenger:tracking:sqs-poll
        {--once : Receive a single batch and exit instead of draining the queue.}
        {--max-messages= : Stop after processing this many messages.}
        {--max-time= : Stop after this many seconds (default: messenger.ses_sns.sqs.schedule.max_run_seconds).}';

    protected $description = 'Drain the SQS queue that SNS fans SES tracking events into (SES -> SNS -> SQS transport).';

    public function handle(SqsTrackingPoller $poller): int
    {
        if (config('messenger.tracking.event_transport') !== 'sqs') {
            $this->warn("messenger.tracking.event_transport is not 'sqs'; polling anyway because the command was invoked explicitly.");
        }

        $maxMessages = $this->option('once')
            ? (int) config('messenger.ses_sns.sqs.poll_max_messages', 10)
            : ($this->option('max-messages') !== null ? (int) $this->option('max-messages') : null);

        $maxTime = $this->option('once')
            ? null
            : ($this->option('max-time') !== null
                ? (int) $this->option('max-time')
                : (int) config('messenger.ses_sns.sqs.schedule.max_run_seconds', 55));

        try {
            $processed = $poller->poll($maxMessages, $maxTime);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Processed {$processed} SES tracking message(s) from SQS.");

        return self::SUCCESS;
    }
}
