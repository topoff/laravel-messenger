<?php

namespace Topoff\MailManager\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\FormData;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Topoff\MailManager\Notifications\NovaChannelNotification;

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

        $sentCount = 0;
        $skippedCount = 0;

        foreach ($models as $model) {
            $route = $this->resolveRouteForChannel($model, $channel);

            if ($route === null) {
                $skippedCount++;

                continue;
            }

            $notifiable = new AnonymousNotifiable;
            $notifiable->route($channel, $route);
            $notifiable->notify(new NovaChannelNotification($subject, $message, $channel));

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
                ->readonly()
                ->dependsOn(['channel'], function (Text $field, NovaRequest $request, FormData $formData): void {
                    if ((string) $formData->channel !== 'vonage') {
                        $field->hide();

                        return;
                    }

                    $field->show();
                    $field->setValue($this->resolveSmsRecipientPreview($request));
                })
                ->help('Effective SMS recipient phone number(s).'),
            Textarea::make(__('Message'), 'message')
                ->default(fn (): string => (string) config('mail-manager.notifications.default_message_footer', ''))
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
