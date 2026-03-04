<?php

namespace Topoff\Messenger\Nova\Actions;

use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Tracking\MessageResender;

class ResendAsNewMessageAction extends Action
{
    public $name = 'Resend As New Message';

    public function handle(ActionFields $fields, Collection $models): Action|ActionResponse|null
    {
        $queued = 0;
        $skipped = 0;
        $resender = app(MessageResender::class);

        foreach ($models as $message) {
            /** @var Message $message */
            if ($message->sent_at === null && $message->error_at === null && $message->failed_at === null) {
                $skipped++;

                continue;
            }

            $resender->resend($message);
            if ($message->sent_at === null && ($message->error_at !== null || $message->failed_at !== null)) {
                $message->delete(); // To avoid duplicate mails, as these with errors are retried over time
            }
            $queued++;
        }

        if ($queued === 0) {
            return Action::danger('Only messages with sent_at, error_at, or failed_at can be resent as new messages.');
        }

        if ($skipped > 0) {
            return Action::message(sprintf('%d resend(s) queued as new message(s), %d skipped.', $queued, $skipped));
        }

        return Action::message(sprintf('%d resend(s) queued as new message(s).', $queued));
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
