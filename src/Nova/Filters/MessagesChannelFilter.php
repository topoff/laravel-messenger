<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class MessagesChannelFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('channel', $value);
    }

    public function options(NovaRequest $request): array
    {
        $messageModel = config('messenger.models.message');

        return Cache::remember('messenger.channels', now()->addDays(7), function () use ($messageModel) {
            return (new $messageModel)
                ->newQuery()
                ->select('channel')
                ->whereNotNull('channel')
                ->distinct()
                ->pluck('channel', 'channel')
                ->toArray();
        });
    }
}
