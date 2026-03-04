<?php

namespace Topoff\Messenger\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class PreviewMessageInBrowserAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function handle(ActionFields $fields, Collection $models): Action|ActionResponse|null
    {
        foreach ($models as $message) {
            $previewUrl = URL::temporarySignedRoute(
                'messenger.tracking.nova.preview-message',
                now()->addMinutes(10),
                ['message' => $message->id]
            );

            return Action::openInNewTab($previewUrl);
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
