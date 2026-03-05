<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class MessagesMessageTypeFilter extends Filter
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
        return $query->where('message_type_id', $value);
    }

    public function options(NovaRequest $request): array
    {
        $messageTypeModel = config('messenger.models.message_type');

        return Cache::remember('messenger.message_types', now()->addDay(), function () use ($messageTypeModel) {
            return (new $messageTypeModel)
                ->newQuery()
                ->pluck('id', 'notification_class')
                ->toArray();
        });
    }
}
