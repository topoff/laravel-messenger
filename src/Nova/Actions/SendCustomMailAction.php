<?php

namespace Topoff\Messenger\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Markdown;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Throwable;
use Topoff\Messenger\Contracts\MessageReceiverInterface;
use Topoff\Messenger\Mail\CustomMessageMail;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Repositories\MessageTypeRepository;
use Topoff\Messenger\Services\MessageService;

class SendCustomMailAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Send Custom Email';

    public function handle(ActionFields $fields, Collection $models): Action|ActionResponse|null
    {
        $subject = trim((string) $fields->get('subject'));
        $markdown = trim((string) $fields->get('markdown'));
        $isPreview = (bool) $fields->get('preview_only');
        $scheduledAt = $this->resolveScheduledAt($fields->get('scheduled_at'));
        $mailer = $fields->get('mailer');

        if ($mailer) {
            config(['mail.default' => $mailer]);
        }

        if ($isPreview) {
            $previewKey = 'messenger:nova-custom-preview:'.Str::uuid();
            Cache::put($previewKey, [
                'subject' => $subject,
                'markdown' => $markdown,
                'model_type' => $models->first()::class,
                'receiver_count' => $models->count(),
            ], now()->addMinutes(10));

            $previewUrl = URL::temporarySignedRoute('messenger.tracking.nova.custom-preview', now()->addMinutes(10), ['key' => $previewKey]);

            return Action::openInNewTab($previewUrl);
        }

        $sender = $this->resolveSender();

        // Standalone mode: send to manually entered recipient email
        $recipientEmail = trim((string) $fields->get('recipient_email'));
        if ($models->isEmpty() && $recipientEmail !== '') {
            $messageType = resolve(MessageTypeRepository::class)
                ->getFromTypeAndCustomer(CustomMessageMail::class);

            /** @var class-string<Message> $messageClass */
            $messageClass = config('messenger.models.message');

            $messageRecord = $messageClass::create([
                'channel' => 'email',
                'message_type_id' => $messageType->id,
                'sender_type' => $sender['class'],
                'sender_id' => $sender['id'],
                'params' => ['subject' => $subject, 'text' => $markdown],
                'scheduled_at' => $scheduledAt,
            ]);

            try {
                Mail::to($recipientEmail)->send(new CustomMessageMail($messageRecord));

                $messageRecord->sent_at = Date::now();
                $messageRecord->save();
            } catch (Throwable $e) {
                $messageRecord->error_at = Date::now();
                $messageRecord->error_message = Str::limit($e->getMessage(), 245);
                $messageRecord->save();

                return Action::danger('Failed to send email: '.Str::limit($e->getMessage(), 100));
            }

            return Action::message("Email sent to {$recipientEmail}.");
        }

        /** @var MessageService $messageService */
        $messageService = resolve(MessageService::class);

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($models as $model) {
            if (! $model instanceof MessageReceiverInterface) {
                Log::warning('SendCustomMailAction: Model '.$model::class.'#'.$model->getKey().' does not implement MessageReceiverInterface, skipping.');
                $skippedCount++;

                continue;
            }

            $messageService
                ->setSender($sender['class'], $sender['id'])
                ->setReceiver($model::class, (int) $model->id)
                ->setMessagable($model::class, (int) $model->id)
                ->setMessageTypeClass(CustomMessageMail::class)
                ->setScheduled($scheduledAt)
                ->setParams(['subject' => $subject, 'text' => $markdown])
                ->create();

            $sentCount++;
        }

        if ($sentCount === 0 && $skippedCount > 0) {
            return Action::danger("{$skippedCount} model(s) skipped: does not implement MessageReceiverInterface.");
        }

        return Action::message("{$sentCount} message(s) queued in messages table.");
    }

    /**
     * @return array{class: string|null, id: int|null}
     */
    protected function resolveSender(): array
    {
        $user = request()->user();

        if ($user && isset($user->id) && is_numeric($user->id)) {
            return ['class' => $user::class, 'id' => (int) $user->id];
        }

        return ['class' => null, 'id' => null];
    }

    protected function resolveScheduledAt(mixed $scheduledAtField): Carbon
    {
        if ($scheduledAtField instanceof Carbon) {
            return $scheduledAtField;
        }

        if (is_string($scheduledAtField) && trim($scheduledAtField) !== '') {
            return Carbon::parse($scheduledAtField);
        }

        return Date::now();
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make(__('Mailer'), 'mailer')
                ->options([
                    'smtp' => 'SMTP',
                    'ses' => 'SES',
                ])
                ->default(config('mail.default'))
                ->rules('required')
                ->help(__('Select the mail transport to use for sending.')),

            Text::make(__('Recipient Email'), 'recipient_email')
                ->rules('nullable', 'email', 'max:255')
                ->help(__('Only used in standalone mode (no selected records). Enter the recipient email address.')),

            Text::make(__('Subject'), 'subject')
                ->rules('required', 'string', 'max:180'),

            Markdown::make(__('Email Body (Markdown)'), 'markdown')
                ->rules('required', 'string', 'max:65000')
                ->fullWidth()
                ->alwaysShow()
                ->help(__('Markdown is rendered in email and preview.')),

            DateTime::make(__('Send At'), 'scheduled_at')
                ->rules('nullable', 'date')
                ->default(fn (): Carbon => Date::now())
                ->help(__('Stored in messages.scheduled_at. Leave unchanged to send immediately.')),

            Boolean::make(__('Preview only (do not queue)'), 'preview_only')
                ->default(false)
                ->help(__('Open a browser preview without creating messages.')),
        ];
    }
}
