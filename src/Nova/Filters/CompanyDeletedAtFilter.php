<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class CompanyDeletedAtFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'Company deleted_at';

    /**
     * @param  Builder<Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        $messageTable = (new (config('messenger.models.message')))->getTable();

        return match ($value) {
            'deleted' => $query->whereIn("{$messageTable}.company_id", function ($sub): void {
                $sub->select('id')->from('companies')->whereNotNull('deleted_at');
            }),
            'active' => $query->whereIn("{$messageTable}.company_id", function ($sub): void {
                $sub->select('id')->from('companies')->whereNull('deleted_at');
            }),
            default => $query,
        };
    }

    public function default(): string
    {
        return 'active';
    }

    public function options(NovaRequest $request): array
    {
        return [
            'Active (null)' => 'active',
            'Deleted (not null)' => 'deleted',
        ];
    }
}
