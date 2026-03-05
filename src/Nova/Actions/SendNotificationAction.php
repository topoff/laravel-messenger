<?php

namespace Topoff\Messenger\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Throwable;
use Topoff\Messenger\Models\Message;
use Topoff\Messenger\Notifications\NovaChannelNotification;
use Topoff\Messenger\Repositories\MessageTypeRepository;

class SendNotificationAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Send Notification (SMS)';

    public function handle(ActionFields $fields, Collection $models): Action|ActionResponse
    {
        $channel = (string) $fields->get('channel');
        $subject = trim((string) $fields->get('subject'));
        $message = trim((string) $fields->get('message'));

        if (! in_array($channel, ['mail', 'vonage'], true)) {
            return Action::danger('Please select a valid channel.');
        }

        if ($message === '') {
            return Action::danger('Message is required.');
        }

        if ($channel === 'mail' && $subject === '') {
            return Action::danger('Subject is required for email notifications.');
        }

        $sender = $this->resolveSender();
        $messageType = resolve(MessageTypeRepository::class)
            ->getFromTypeAndCustomer(NovaChannelNotification::class);

        // Standalone mode: send to manually entered recipient
        $recipientPhone = trim((string) $fields->get('recipient_phone'));
        if ($models->isEmpty() && $recipientPhone !== '') {
            $messageRecord = $this->createMessageRecord(
                channel: $channel,
                messageTypeId: $messageType->id,
                sender: $sender,
                params: ['subject' => $subject, 'message' => $message],
            );

            $notification = new NovaChannelNotification($subject, $message, $channel);
            $notification->messengerMessageId = $messageRecord->id;

            try {
                $notifiable = new AnonymousNotifiable;
                $notifiable->route($channel, Str::replace(' ', '', $recipientPhone));
                $notifiable->notify($notification);

                $messageRecord->sent_at = Date::now();
                $messageRecord->save();
            } catch (Throwable $e) {
                $messageRecord->error_at = Date::now();
                $messageRecord->error_message = Str::limit($e->getMessage(), 245);
                $messageRecord->save();

                return Action::danger('Failed to send notification: '.Str::limit($e->getMessage(), 100));
            }

            return Action::message('Notification sent to '.$recipientPhone.'.');
        }

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($models as $model) {
            $route = $this->resolveRouteForChannel($model, $channel);

            if ($route === null) {
                $skippedCount++;

                continue;
            }

            $messageRecord = $this->createMessageRecord(
                channel: $channel,
                messageTypeId: $messageType->id,
                sender: $sender,
                params: ['subject' => $subject, 'message' => $message],
                receiverType: $model::class,
                receiverId: (int) $model->id,
                messagableType: $model::class,
                messagableId: (int) $model->id,
            );

            $notification = new NovaChannelNotification($subject, $message, $channel);
            $notification->messengerMessageId = $messageRecord->id;

            try {
                $notifiable = new AnonymousNotifiable;
                $notifiable->route($channel, $route);
                $notifiable->notify($notification);

                $messageRecord->sent_at = Date::now();
                $messageRecord->save();
            } catch (Throwable $e) {
                $messageRecord->error_at = Date::now();
                $messageRecord->error_message = Str::limit($e->getMessage(), 245);
                $messageRecord->save();
            }

            $sentCount++;
        }

        return Action::message("Notifications sent: {$sentCount}, skipped: {$skippedCount}.");
    }

    public function fields(NovaRequest $request): array
    {
        return [
            Select::make(__('Channel'), 'channel')
                ->options([
                    'mail' => 'Email',
                    'vonage' => 'SMS',
                ])
                ->displayUsingLabels()
                ->default('vonage')
                ->rules('required'),
            Text::make(__('Subject (Email only)'), 'subject')
                ->help('Required when channel is Email.')
                ->dependsOn(['channel'], function (Text $field, NovaRequest $request, FormData $formData): void {
                    if ((string) $formData->channel === 'mail') {
                        $field->show();

                        return;
                    }

                    $field->hide();
                }),
            Text::make(__('Recipient Phone'), 'recipient_phone')
                ->dependsOn(['channel'], function (Text $field, NovaRequest $request, FormData $formData): void {
                    if ((string) $formData->channel !== 'vonage') {
                        $field->hide();

                        return;
                    }

                    $field->show();
                    $preview = $this->resolveSmsRecipientPreview($request);
                    $isStandalone = $preview === 'No recipient selected.';

                    if ($isStandalone) {
                        $field->readonly(false);
                        $field->rules('required');
                    } else {
                        $field->readonly();
                        $field->setValue($preview);
                    }
                })
                ->help('Effective SMS recipient phone number(s). Editable when used as standalone action.'),
            Textarea::make(__('Message'), 'message')
                ->default(fn (): string => (string) config('messenger.notifications.default_message_footer', ''))
                ->rules('required'),
            Text::make(__('SMS Counter'), 'sms_counter')
                ->readonly()
                ->dependsOn(['channel', 'message'], function (Text $field, NovaRequest $request, FormData $formData): void {
                    if ((string) $formData->channel !== 'vonage') {
                        $field->setValue('Only relevant for SMS channel.');

                        return;
                    }

                    $metrics = $this->calculateSmsMetrics((string) $formData->message);
                    $fitsInOneSms = $metrics['segments'] === 1 ? 'yes' : 'no';

                    $field->setValue("{$metrics['encoding']} | chars: {$metrics['length']} | segments: {$metrics['segments']} | one SMS: {$fitsInOneSms}");
                })
                ->help('Shows SMS length and if the message fits into one SMS. Schweiz: 6.7rp / sms'),
        ];
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

    /**
     * @param  array{class: string|null, id: int|null}  $sender
     */
    protected function createMessageRecord(
        string $channel,
        int $messageTypeId,
        array $sender,
        array $params,
        ?string $receiverType = null,
        ?int $receiverId = null,
        ?string $messagableType = null,
        ?int $messagableId = null,
    ): Message {
        /** @var class-string<Message> $messageClass */
        $messageClass = config('messenger.models.message');

        return $messageClass::create([
            'channel' => $channel,
            'message_type_id' => $messageTypeId,
            'sender_type' => $sender['class'],
            'sender_id' => $sender['id'],
            'receiver_type' => $receiverType,
            'receiver_id' => $receiverId,
            'messagable_type' => $messagableType,
            'messagable_id' => $messagableId,
            'params' => $params,
            'scheduled_at' => Date::now(),
        ]);
    }

    /**
     * @return array{encoding: string, length: int, segments: int}
     */
    protected function calculateSmsMetrics(string $message): array
    {
        $characters = preg_split('//u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $extendedGsmChars = ['^', '{', '}', '\\', '[', '~', ']', '|', '€'];

        $isGsm = true;
        $gsmLength = 0;

        foreach ($characters as $character) {
            if (! $this->isGsmCharacter($character)) {
                $isGsm = false;

                break;
            }

            $gsmLength += in_array($character, $extendedGsmChars, true) ? 2 : 1;
        }

        if ($isGsm) {
            $segments = $gsmLength <= 160 ? 1 : (int) ceil($gsmLength / 153);

            return [
                'encoding' => 'GSM-7',
                'length' => $gsmLength,
                'segments' => $segments,
            ];
        }

        $unicodeLength = mb_strlen($message);
        $segments = $unicodeLength <= 70 ? 1 : (int) ceil($unicodeLength / 67);

        return [
            'encoding' => 'UCS-2',
            'length' => $unicodeLength,
            'segments' => $segments,
        ];
    }

    protected function isGsmCharacter(string $character): bool
    {
        static $gsmCharacters = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ`¿abcdefghijklmnopqrstuvwxyzäöñüà^{}\\[~]|€";

        return mb_strpos($gsmCharacters, $character) !== false;
    }

    protected function resolveRouteForChannel(object $model, string $channel): ?string
    {
        if ($channel === 'mail') {
            $email = trim((string) data_get($model, 'email'));

            return $email !== '' ? $email : null;
        }

        $phone = trim((string) data_get($model, 'phone'));
        if ($phone === '') {
            return null;
        }

        if (! App::isProduction()) {
            $devTarget = trim((string) config('services.vonage.dev_sms_global_to_number'));
            if ($devTarget !== '') {
                return $devTarget;
            }
        }

        return Str::replace(' ', '', $phone);
    }

    protected function resolveSmsRecipientPreview(NovaRequest $request): string
    {
        $selectedModels = $this->resolveSelectedModels($request);
        if ($selectedModels->isEmpty()) {
            return 'No recipient selected.';
        }

        $numbers = $selectedModels
            ->map(fn (object $model): ?string => $this->resolveRouteForChannel($model, 'vonage'))
            ->filter(fn (?string $number): bool => is_string($number) && $number !== '')
            ->unique()
            ->values();

        if ($numbers->isEmpty()) {
            return 'No phone number available for selected record(s).';
        }

        if ($numbers->count() <= 3) {
            return $numbers->implode(', ');
        }

        return $numbers->take(3)->implode(', ').' +'.($numbers->count() - 3).' more';
    }

    /**
     * @return Collection<int, object>
     */
    protected function resolveSelectedModels(NovaRequest $request): Collection
    {
        if (method_exists($request, 'allResourcesSelected') && $request->allResourcesSelected() && method_exists($request, 'toQuery')) {
            /** @var Collection<int, object> $allSelected */
            $allSelected = collect((clone $request->toQuery())->limit(20)->get()->all());

            return $allSelected;
        }

        $selected = method_exists($request, 'selectedResources') ? $request->selectedResources() : null;
        if ($selected instanceof EloquentCollection) {
            /** @var Collection<int, object> $eloquent */
            $eloquent = collect($selected->all());

            return $eloquent;
        }

        if ($selected instanceof Collection) {
            /** @var Collection<int, object> $selected */
            return $selected;
        }

        return collect();
    }
}
