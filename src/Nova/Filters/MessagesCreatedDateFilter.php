<?php

namespace Topoff\MailManager\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;
use Override;

class MessagesCreatedDateFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'Created Date';

    public function __construct(protected string $column = 'created_at', protected ?string $defaultDateRange = 'month')
    {
    }

    #[Override]
    public function key(): string
    {
        return 'MessagesCreatedDate_'.$this->column;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return match ($value) {
            'today' => $query->whereBetween($this->column, [Date::today()->startOfDay(), Date::today()->endOfDay()]),
            'yesterday' => $query->whereBetween($this->column, [Date::today()->subDay()->startOfDay(), Date::today()->subDay()->endOfDay()]),
            'week' => $query->whereBetween($this->column, [Date::today()->startOfWeek(), Date::today()->endOfWeek()]),
            'last-week' => $query->whereBetween($this->column, [Date::today()->subWeek()->startOfWeek(), Date::today()->subWeek()->endOfWeek()]),
            '7-days' => $query->whereBetween($this->column, [Date::today()->subDays(7)->startOfDay(), Date::today()->endOfDay()]),
            '30-days' => $query->whereBetween($this->column, [Date::today()->subDays(30)->startOfDay(), Date::today()->endOfDay()]),
            'month' => $query->whereBetween($this->column, [Date::today()->startOfMonth(), Date::today()->endOfMonth()]),
            'last-month' => $query->whereBetween($this->column, [Date::today()->subMonthNoOverflow()->startOfMonth(), Date::today()->subMonthNoOverflow()->endOfMonth()]),
            'this-year' => $query->whereBetween($this->column, [Date::today()->startOfYear(), Date::today()->endOfYear()]),
            'all-time' => $query,
            default => $query,
        };
    }

    #[Override]
    public function default(): ?string
    {
        return $this->defaultDateRange;
    }

    #[Override]
    public function options(NovaRequest $request): array
    {
        return [
            'Today' => 'today',
            'Yesterday' => 'yesterday',
            'This Week' => 'week',
            'Last Week' => 'last-week',
            'Last 7 Days' => '7-days',
            'Last 30 Days' => '30-days',
            'This Month' => 'month',
            'Last Month' => 'last-month',
            'This Year' => 'this-year',
            'All Time' => 'all-time',
        ];
    }
}
