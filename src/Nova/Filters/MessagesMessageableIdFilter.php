<?php

namespace Topoff\MailManager\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class MessagesMessageableIdFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'text-filter';

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('messagable_id', $value);
    }
}
