<?php

namespace Topoff\MailManager\Nova\Lenses;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Lenses\Lens;
use Topoff\MailManager\Nova\Filters\MessagesCreatedDateFilter;

class MessagesByTypeTrackingLens extends Lens
{
    /**
     * Get the query builder / paginator for the lens.
     */
    public static function query(LensRequest $request, Builder $query): Builder
    {
        $messageTypeTable = (new (config('mail-manager.models.message_type')))->getTable();
        $messageTable = (new (config('mail-manager.models.message')))->getTable();

        $query = $query->from($messageTable)->select([
            "{$messageTable}.message_type_id",
            DB::raw("{$messageTypeTable}.mail_class"),
            DB::raw("COUNT(*) as total_messages"),
            DB::raw("COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END) as total_sent"),
            DB::raw("SUM({$messageTable}.tracking_opens) as total_opens"),
            DB::raw("SUM({$messageTable}.tracking_clicks) as total_clicks"),
            DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) as unique_opened"),
            DB::raw("COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) as unique_clicked"),
            DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_opened_at IS NOT NULL THEN 1 END) / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0) * 100, 2) as open_rate"),
            DB::raw("ROUND(COUNT(CASE WHEN {$messageTable}.tracking_clicked_at IS NOT NULL THEN 1 END) / NULLIF(COUNT(CASE WHEN {$messageTable}.sent_at IS NOT NULL THEN 1 END), 0) * 100, 2) as click_rate"),
        ])
            ->join($messageTypeTable, "{$messageTable}.message_type_id", '=', "{$messageTypeTable}.id")
            ->groupBy("{$messageTable}.message_type_id", "{$messageTypeTable}.mail_class");

        return $request->withOrdering(
            $request->withFilters($query),
            fn(Builder $query): Builder => $query->orderBy('total_messages', 'desc'),
        );
    }

    /**
     * Get the fields available to the lens.
     *
     * @return array<int, mixed>
     */
    public function fields(Request $request): array
    {
        return [
            Text::make('Message Type', 'mail_class')->sortable(),
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
            new MessagesCreatedDateFilter("{$messageTable}.created_at", '30-days'),
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
        return 'Tracking Metrics';
    }
}
