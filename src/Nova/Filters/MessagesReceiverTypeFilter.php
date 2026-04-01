<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class MessagesReceiverTypeFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    /**
     * @param  Builder<Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('receiver_type', $value);
    }

    public function options(NovaRequest $request): array
    {
        $messageModel = config('messenger.models.message');

        return Cache::remember('messenger.receiver_types', now()->addDay(), fn () => (new $messageModel)
            ->newQuery()
            ->select('receiver_type')
            ->whereNotNull('receiver_type')
            ->distinct()
            ->pluck('receiver_type', 'receiver_type')
            ->toArray());
    }
}
