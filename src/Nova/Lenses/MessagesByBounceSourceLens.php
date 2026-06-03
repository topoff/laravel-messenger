<?php

namespace Topoff\Messenger\Nova\Lenses;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Lenses\Lens;
use Topoff\Messenger\Nova\Filters\DateFilter;
use Topoff\Messenger\Nova\Filters\MessagesMessageTypeFilter;

/**
 * Bounces grouped by their reporting source — SNS (authoritative SES events),
 * IMAP (parsed from the reply-to inbox), Both (the same message bounced via
 * both pipes), or Unknown (bounced_at set but no source signature in
 * tracking_meta — typically legacy rows from before this package wrote the
 * source markers).
 *
 * Useful to spot the share of bounces that arrive only over IMAP — which
 * indicates accept-then-bounce traffic from MTAs that DSN to Reply-To
 * instead of via the SES Return-Path.
 */
class MessagesByBounceSourceLens extends Lens
{
    public static function query(LensRequest $request, Builder $query): Builder
    {
        $table = (new (config('messenger.models.message')))->getTable();
        $sourceExpr = self::bounceSourceExpression("{$table}.tracking_meta");

        $query = $query->from($table)->select([
            DB::raw("{$sourceExpr} as bounce_source"),
            DB::raw('COUNT(*) as total_bounced'),
            DB::raw("COUNT(CASE WHEN {$table}.delivered_at IS NOT NULL THEN 1 END) as with_prior_delivery"),
            DB::raw("MIN({$table}.bounced_at) as first_bounced_at"),
            DB::raw("MAX({$table}.bounced_at) as last_bounced_at"),
        ])
            ->whereNotNull("{$table}.bounced_at")
            ->groupBy('bounce_source');

        return $request->withOrdering(
            $request->withFilters($query),
            fn (Builder $q): Builder => $q->orderBy('total_bounced', 'desc'),
        );
    }

    /**
     * Database-portable expression that classifies tracking_meta into one of
     * 'sns' / 'imap' / 'both' / 'unknown' based on which bounce-source key
     * is present.
     */
    public static function bounceSourceExpression(string $column): string
    {
        $driver = DB::getDriverName();
        $hasImap = self::jsonKeyPresent($driver, $column, 'imap_message_bounce');
        $hasSns = self::jsonKeyPresent($driver, $column, 'sns_message_bounce');

        return <<<SQL
            CASE
                WHEN ({$hasImap}) AND ({$hasSns}) THEN 'both'
                WHEN ({$hasImap}) THEN 'imap'
                WHEN ({$hasSns}) THEN 'sns'
                ELSE 'unknown'
            END
        SQL;
    }

    private static function jsonKeyPresent(string $driver, string $column, string $key): string
    {
        return match ($driver) {
            'pgsql' => "({$column}->>'{$key}') IS NOT NULL",
            default => "JSON_EXTRACT({$column}, '$.{$key}') IS NOT NULL",
        };
    }

    /**
     * @return array<int, mixed>
     */
    public function fields(Request $request): array
    {
        return [
            Text::make('Source', 'bounce_source')->sortable(),
            Number::make('Bounced Messages', 'total_bounced')->sortable(),
            Number::make('With Prior Delivery', 'with_prior_delivery')->sortable(),
            DateTime::make('First Bounce', 'first_bounced_at')->sortable(),
            DateTime::make('Last Bounce', 'last_bounced_at')->sortable(),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public function filters(Request $request): array
    {
        $table = (new (config('messenger.models.message')))->getTable();

        return [
            new DateFilter("{$table}.created_at", '30-days'),
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
        return 'Bounces by Source';
    }
}
