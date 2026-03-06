<?php

namespace Topoff\Messenger\Nova\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

class CompanyStatusFilter extends Filter
{
    /**
     * @var string
     */
    public $component = 'select-filter';

    public $name = 'Company Status';

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  mixed  $value
     */
    public function apply(NovaRequest $request, $query, $value): Builder
    {
        $messageTable = (new (config('messenger.models.message')))->getTable();

        return $query->whereIn("{$messageTable}.company_id", function ($sub) use ($value): void {
            $sub->select('id')
                ->from('companies')
                ->where('status', $value);
        });
    }

    public function options(NovaRequest $request): array
    {
        return DB::connection('auth')
            ->table('companies')
            ->select('status')
            ->whereNotNull('status')
            ->distinct()
            ->orderBy('status')
            ->pluck('status', 'status')
            ->mapWithKeys(function (int $status): array {
                $label = class_exists(\App\Enums\CompanyStatus::class)
                    ? \App\Enums\CompanyStatus::fromValue($status)->description
                    : (string) $status;

                return [$label => $status];
            })
            ->toArray();
    }
}
