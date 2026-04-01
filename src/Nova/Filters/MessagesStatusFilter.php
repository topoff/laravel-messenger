<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class MessagesStatusFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public function default(): string
    {
        return '';
    }

    /**
     * @param  Builder<Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->whereNotNull($value);
    }

    public function options(NovaRequest $request): array
    {
        return [
            'Scheduled' => 'scheduled_at',
            'Reserved' => 'reserved_at',
            'Sent' => 'sent_at',
            'Error' => 'error_at',
            'Failed' => 'failed_at',
        ];
    }
}
