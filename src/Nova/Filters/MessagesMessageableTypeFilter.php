<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class MessagesMessageableTypeFilter extends Filter
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
        return $query->where('messagable_type', $value);
    }

    public function options(NovaRequest $request): array
    {
        $messageModel = config('messenger.models.message');

        return (new $messageModel)
            ->newQuery()
            ->select('messagable_type')
            ->whereNotNull('messagable_type')
            ->distinct()
            ->pluck('messagable_type', 'messagable_type')
            ->toArray();
    }
}
