<?php

namespace Topoff\MailManager\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
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
use Topoff\MailManager\Contracts\MessageReceiverInterface;
use Topoff\MailManager\Mail\CustomMessageMail;
use Topoff\MailManager\Services\MessageService;

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
            $previewKey = 'mail-manager:nova-custom-preview:'.Str::uuid();
            Cache::put($previewKey, [
                'subject' => $subject,
                'markdown' => $markdown,
                'model_type' => $models->first()::class,
                'receiver_count' => $models->count(),
            ], now()->addMinutes(10));

            $previewUrl = URL::temporarySignedRoute('mail-manager.tracking.nova.custom-preview', now()->addMinutes(10), ['key' => $previewKey]);

            return Action::openInNewTab($previewUrl);
        }

        /** @var MessageService $messageService */
        $messageService = resolve(MessageService::class);
        $sender = $this->resolveSender();

        $sentCount = 0;
        foreach ($models as $model) {
            if (! $model instanceof MessageReceiverInterface) {
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

        return Action::message("{$sentCount} message(s) queued in messages table.");
    }

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
