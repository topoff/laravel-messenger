<?php

namespace Topoff\MailManager\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
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
        $messageTypeModel = config('mail-manager.models.message_type');

        return (new $messageTypeModel)
            ->newQuery()
            ->pluck('id', 'mail_class')
            ->toArray();
    }
}
