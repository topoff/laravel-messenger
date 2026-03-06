<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class CompanyFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'Company';

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        return $query->where('company_id', $value);
    }

    public function options(NovaRequest $request): array
    {
        $messageTable = (new (config('messenger.models.message')))->getTable();

        return DB::table($messageTable)
            ->whereNotNull('company_id')
            ->select('company_id')
            ->distinct()
            ->orderBy('company_id')
            ->pluck('company_id', 'company_id')
            ->mapWithKeys(fn (int $id): array => ["{$id}" => $id])
            ->toArray();
    }
}
