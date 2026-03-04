<?php

namespace Topoff\MailManager\Nova\Lenses;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Lenses\Lens;
use Topoff\MailManager\Nova\Filters\DateFilter;
use Topoff\MailManager\Nova\Filters\MessagesMessageTypeFilter;

class MessagesByDomainTrackingLens extends Lens
{
    /**
     * Get the query builder / paginator for the lens.
     */
    public static function query(LensRequest $request, Builder $query): Builder
    {
        $messageTable = (new (config('mail-manager.models.message')))->getTable();
        $domainExpr = static::domainExpression("{$messageTable}.tracking_recipient_contact");

        $query = $query->from($messageTable)->select([
            DB::raw("{$domainExpr} as domain"),
            DB::raw('COUNT(*) as total_messages'),
            DB::raw("COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END) as total_sent"),
            DB::raw("SUM({$messageTable}.tracking_opens) as total_opens"),
            DB::raw("SUM({$messageTable}.tracking_clicks) as total_clicks"),
            DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) as unique_opened"),
            DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) as unique_clicked"),
            DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0), 2) as open_rate"),
            DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) * 100.0 / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0), 2) as click_rate"),
        ])
            ->whereNotNull("{$messageTable}.tracking_recipient_contact")
            ->groupBy('domain');

        return $request->withOrdering(
            $request->withFilters($query),
            fn (Builder $query): Builder => $query->orderBy('total_messages', 'desc'),
        );
    }

    /**
     * Get a database-portable expression to extract the domain from an email column.
     */
    public static function domainExpression(string $column): string
    {
        $driver = DB::getDriverName();

        return match ($driver) {
            'mysql', 'mariadb' => "SUBSTRING_INDEX({$column}, '@', -1)",
            'pgsql' => "SPLIT_PART({$column}, '@', 2)",
            default => "SUBSTR({$column}, INSTR({$column}, '@') + 1)",
        };
    }

    /**
     * Get the fields available to the lens.
     *
     * @return array<int, mixed>
     */
    public function fields(Request $request): array
    {
        return [
            Text::make('Domain', 'domain')->sortable(),
            Number::make('Total Messages', 'total_messages')->sortable(),
            Number::make('Total Sent', 'total_sent')->sortable(),
            Number::make('Total Opens', 'total_opens')->sortable(),
            Number::make('Total Clicks', 'total_clicks')->sortable(),
            Number::make('Unique Opened', 'unique_opened')->sortable(),
            Number::make('Unique Clicked', 'unique_clicked')->sortable(),
            Number::make('Open Rate %', 'open_rate')->sortable(),
            Number::make('Click Rate %', 'click_rate')->sortable(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function filters(Request $request): array
    {
        $messageTable = (new (config('mail-manager.models.message')))->getTable();

        return [
            new DateFilter("{$messageTable}.created_at", '30-days'),
            new MessagesMessageTypeFilter,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function actions(Request $request): array
    {
        return [];
    }

    public function name(): string
    {
        return 'Domain Tracking Metrics';
    }
}
