<?php

namespace Topoff\Messenger\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class OpenSesSnsSiteAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Open SES/SNS Dashboard';

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $url = URL::temporarySignedRoute(
            'messenger.ses-sns.dashboard',
            now()->addMinutes(30)
        );

        return Action::openInNewTab($url);
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
