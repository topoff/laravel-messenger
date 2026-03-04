<?php

namespace Topoff\MailManager\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Topoff\MailManager\Models\MessageType;

class PreviewMessageTypeInBrowserAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Preview MessageType In Browser';

    public $confirmButtonText = 'Preview';

    public function handle(ActionFields $fields, Collection $models): Action|ActionResponse|null
    {
        /** @var MessageType|null $messageType */
        $messageType = $models->first();

        if (! $messageType) {
            return Action::danger('No message type selected.');
        }

        $messageId = (int) $fields->get('message_id');
        $messageModelClass = config('mail-manager.models.message');
        $message = $messageModelClass::query()
            ->where('id', $messageId)
            ->where('message_type_id', $messageType->id)
            ->first();

        if (! $message) {
            return Action::danger('Please select a valid message for this message type.');
        }

        $previewUrl = URL::temporarySignedRoute(
            'mail-manager.tracking.nova.preview-message',
            now()->addMinutes(10),
            ['message' => $message->id]
        );

        return Action::openInNewTab($previewUrl);
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        $messageTypeId = $this->resolveSelectedMessageTypeId($request);
        $options = [];

        if ($messageTypeId) {
            $messageModelClass = config('mail-manager.models.message');
            $options = $messageModelClass::query()
                ->where('message_type_id', $messageTypeId)
                ->orderByDesc('created_at')
                ->limit(25)
                ->get()
                ->mapWithKeys(function (Model $message): array {
                    $createdAt = $message->created_at?->format('Y-m-d H:i');
                    $recipient = $message->tracking_recipient_contact ?: ($message->receiver_id ? 'receiverId#'.$message->receiver_id : 'n/a');
                    $locale = $message->locale ?: $message->language ?: 'n/a';
                    $messagableType = $message->messagable_type ? Str::afterLast($message->messagable_type, '\\') : 'n/a';
                    $receiverType = $message->receiver_type ? Str::afterLast($message->receiver_type, '\\') : 'n/a';

                    return [
                        (string) $message->id => '#'.$message->id
                            .' | '.$recipient
                            .' | locale:'.$locale
                            .' | messagable:'.$messagableType
                            .' | receiver:'.$receiverType
                            .' | '.$createdAt,
                    ];
                })
                ->all();
        }

        return [
            Select::make('Message', 'message_id')
                ->options($options)
                ->displayUsingLabels()
                ->rules('required')
                ->help('Choose one of the latest 25 messages for preview.'),
        ];
    }

    private function resolveSelectedMessageTypeId(NovaRequest $request): ?int
    {
        $resources = $request->input('resources');

        if (is_array($resources) && count($resources) === 1 && is_numeric($resources[0])) {
            return (int) $resources[0];
        }

        if (is_numeric($resources)) {
            return (int) $resources;
        }

        $resourceId = $request->input('resourceId');

        return is_numeric($resourceId) ? (int) $resourceId : null;
    }
}
